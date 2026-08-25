<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class SalesChart extends ChartWidget
{
    protected ?string $heading = 'Sales';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7'   => 'Last 7 days',
            '30'  => 'Last 30 days',
            '90'  => 'Last 3 months',
            '365' => 'Last 12 months',
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->canManage('sales') ?? false;
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);
        $byMonth = $days > 90;

        $start = now()->subDays($days)->startOfDay();

        $orders = Order::query()
            ->whereNot('status', 'cancelled')
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'total']);

        $buckets = [];
        $cursor = $start->copy();

        // Pre-fill every bucket so quiet days still show as zero.
        while ($cursor <= now()) {
            $buckets[$this->key($cursor, $byMonth)] = ['revenue' => 0.0, 'orders' => 0];
            $cursor = $byMonth ? $cursor->addMonth() : $cursor->addDay();
        }

        foreach ($orders as $order) {
            $key = $this->key($order->created_at, $byMonth);

            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['revenue'] += (float) $order->total;
            $buckets[$key]['orders']++;
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Revenue',
                    'data'            => array_column($buckets, 'revenue'),
                    'borderColor'     => 'rgb(47, 122, 62)',
                    'backgroundColor' => 'rgba(47, 122, 62, 0.12)',
                    'fill'            => true,
                    'tension'         => 0.3,
                    'yAxisID'         => 'y',
                ],
                [
                    'label'           => 'Orders',
                    'data'            => array_column($buckets, 'orders'),
                    'borderColor'     => 'rgb(240, 169, 43)',
                    'backgroundColor' => 'rgba(240, 169, 43, 0.15)',
                    'fill'            => false,
                    'tension'         => 0.3,
                    'yAxisID'         => 'y1',
                ],
            ],
            'labels' => array_keys($buckets),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y'  => ['position' => 'left', 'beginAtZero' => true],
                'y1' => [
                    'position' => 'right',
                    'beginAtZero' => true,
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function key(Carbon $date, bool $byMonth): string
    {
        return $byMonth ? $date->format('M Y') : $date->format('d M');
    }
}
