<?php

namespace App\Services\RAG;

class RetrievalMetrics
{
    /**
     * Binary-relevance IR metrics for one query.
     *
     * @param  array<int, int|string>  $rankedIds  chunk IDs in rank order
     * @param  array<int, int|string>  $relevantIds
     * @param  array<int, int>  $ks
     * @return array<string, float|null>
     */
    public static function score(array $rankedIds, array $relevantIds, array $ks = [1, 3, 5]): array
    {
        $ranked = array_values(array_map('intval', $rankedIds));
        $relevant = array_values(array_unique(array_map('intval', $relevantIds)));

        if ($relevant === []) {
            return self::emptyScore($ks);
        }

        $relevantSet = array_flip($relevant);
        $out = [];

        foreach ($ks as $k) {
            $top = array_slice($ranked, 0, $k);
            $hits = count(array_filter($top, fn ($id) => isset($relevantSet[$id])));
            $out["recall_at_{$k}"] = (float) ($hits / count($relevant));
            $out["precision_at_{$k}"] = $k > 0 ? (float) ($hits / $k) : 0.0;
        }

        $out['mrr_at_5'] = (float) self::mrr(array_slice($ranked, 0, 5), $relevantSet);
        $out['ndcg_at_5'] = (float) self::ndcg(array_slice($ranked, 0, 5), $relevantSet, count($relevant));
        $out['hit_at_5'] = $out['recall_at_5'] > 0 ? 1.0 : 0.0;

        return $out;
    }

    /**
     * @param  array<int, array<string, float|null>>  $rows
     * @param  array<int, string>  $keys
     * @return array<string, float|null>
     */
    public static function aggregate(array $rows, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $values = array_values(array_filter(
                array_map(fn ($row) => $row[$key] ?? null, $rows),
                fn ($v) => $v !== null
            ));
            $out[$key] = $values === [] ? null : (float) round(array_sum($values) / count($values), 4);
        }

        return $out;
    }

    /**
     * @param  array<int, float|int>  $values
     */
    public static function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        if ($n === 1) {
            return round((float) $values[0], 2);
        }

        $rank = ($p / 100) * ($n - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        $weight = $rank - $low;
        $value = $values[$low] * (1 - $weight) + $values[$high] * $weight;

        return round((float) $value, 2);
    }

    /**
     * @return array<string, float|null>
     */
    private static function emptyScore(array $ks): array
    {
        $out = [];
        foreach ($ks as $k) {
            $out["recall_at_{$k}"] = null;
            $out["precision_at_{$k}"] = null;
        }
        $out['mrr_at_5'] = null;
        $out['ndcg_at_5'] = null;
        $out['hit_at_5'] = null;

        return $out;
    }

    /**
     * @param  array<int, int>  $ranked
     * @param  array<int, int>  $relevantSet
     */
    private static function mrr(array $ranked, array $relevantSet): float
    {
        foreach ($ranked as $i => $id) {
            if (isset($relevantSet[$id])) {
                return 1.0 / ($i + 1);
            }
        }

        return 0.0;
    }

    /**
     * @param  array<int, int>  $ranked
     * @param  array<int, int>  $relevantSet
     */
    private static function ndcg(array $ranked, array $relevantSet, int $relevantCount): float
    {
        $dcg = 0.0;
        foreach ($ranked as $i => $id) {
            if (isset($relevantSet[$id])) {
                $dcg += 1.0 / log($i + 2, 2);
            }
        }

        $ideal = 0.0;
        for ($i = 0; $i < min($relevantCount, count($ranked)); $i++) {
            $ideal += 1.0 / log($i + 2, 2);
        }

        return $ideal > 0 ? min(1.0, $dcg / $ideal) : 0.0;
    }
}
