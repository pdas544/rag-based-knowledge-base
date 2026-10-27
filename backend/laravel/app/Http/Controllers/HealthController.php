<?php

namespace App\Http\Controllers;

use App\Services\Qdrant\QdrantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthController extends Controller
{
    /**
     * Liveness/readiness probe for the load balancer and ops dashboards.
     * Public on purpose: probes cannot present a Sanctum token.
     */
    public function __invoke(QdrantService $qdrant): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1') !== []),
            'cache' => $this->check(function () {
                Cache::put('health:probe', 'ok', 5);

                return Cache::get('health:probe') === 'ok';
            }),
            'qdrant' => $this->check(fn () => $qdrant->ready()),
            'queue' => [
                'ok' => true,
                'driver' => (string) config('queue.default', 'sync'),
                'failed_jobs' => $this->failedJobs(),
            ],
        ];

        // Only core dependencies gate readiness; Qdrant outages degrade
        // retrieval (keyword fallback still works) but must not fail the app.
        $healthy = $checks['database']['ok'] && $checks['cache']['ok'];

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private function failedJobs(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
