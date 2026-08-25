<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Best sellers</x-slot>
        <x-slot name="description">By revenue, excluding cancelled orders.</x-slot>

        @php($rows = $this->getRows())

        @if (empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Nothing has sold yet — this fills in as orders come through.
            </p>
        @else
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $index => $row)
                    <div class="flex items-center gap-4 py-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                            {{ $index + 1 }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['sku'] }}</p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $row['revenue'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['sold'] }} sold</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
