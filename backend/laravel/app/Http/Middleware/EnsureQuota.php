<?php

namespace App\Http\Middleware;

use App\Models\UserQuota;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isAdmin()) {
            return $next($request);
        }

        $quota = UserQuota::forToday($user->id);

        if ($quota->prompt_count >= 30 || $quota->token_count >= 20000) {
            return response()->json(
                ['message' => 'Daily quota exceeded.'],
                429,
                ['Retry-After' => (string) now()->endOfDay()->diffInSeconds(now())]
            );
        }

        return $next($request);
    }
}
