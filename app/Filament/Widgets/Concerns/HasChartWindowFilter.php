<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The 24h/7d/30d window selector shared by every breakdown chart on the
 * dashboard, plus the "top N slices, everything else folded into one" and
 * byte-unit-scaling helpers a pie/doughnut of a long-tailed column (countries,
 * users, services) always ends up needing.
 */
trait HasChartWindowFilter
{
    protected function getFilters(): ?array
    {
        // Keys are the stored filter values and stay English; only what the
        // select shows is translated.
        return [
            '24h' => __('Last 24 hours'),
            '7d' => __('Last 7 days'),
            '30d' => __('Last 30 days'),
        ];
    }

    protected function windowStart(): Carbon
    {
        return match ($this->filter ?? '7d') {
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };
    }

    /**
     * @param  Collection<int, object>  $rows  Already ordered largest-first.
     * @return array{0: list<string>, 1: list<int>}
     */
    protected function topNWithOthers(Collection $rows, string $labelAttr, string $valueAttr, int $limit = 7): array
    {
        $top = $rows->take($limit);
        $rest = (int) $rows->slice($limit)->sum($valueAttr);

        $labels = $top->pluck($labelAttr)->all();
        $data = $top->pluck($valueAttr)->map(fn ($value) => (int) $value)->all();

        if ($rest > 0) {
            $labels[] = __('Other');
            $data[] = $rest;
        }

        return [$labels, $data];
    }

    /**
     * A fixed, distinct set rather than one generated per-request: it has to
     * stay stable slice-to-slice across polling refreshes, and match visually
     * across the several breakdown charts sitting next to each other.
     *
     * @return list<string>
     */
    protected function palette(): array
    {
        return ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#f43f5e', '#06b6d4', '#84cc16', '#f97316'];
    }

    /**
     * @return array{0: int, 1: string}
     */
    protected function unitFor(int $peakBytes): array
    {
        foreach ([[1024 ** 3, 'GB'], [1024 ** 2, 'MB'], [1024, 'KB']] as [$divisor, $unit]) {
            if ($peakBytes >= $divisor) {
                return [$divisor, $unit];
            }
        }

        return [1, 'B'];
    }
}
