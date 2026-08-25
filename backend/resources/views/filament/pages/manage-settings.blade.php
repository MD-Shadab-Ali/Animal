<x-filament-panels::page>
    <form wire:submit="save" class="fi-form grid gap-y-6">
        {{ $this->form }}

        <div class="fi-form-actions">
            <div class="fi-ac gap-3 flex flex-wrap items-center justify-start">
                @foreach ($this->getFormActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </div>
    </form>
</x-filament-panels::page>
