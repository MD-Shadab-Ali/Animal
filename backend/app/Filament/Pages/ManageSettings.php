<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use UnitEnum;

class ManageSettings extends Page
{
    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Site settings';

    protected static ?string $title = 'Site settings';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canManage('configuration') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Human labels for each settings group, in the order they should appear. */
    private const GROUP_LABELS = [
        'general'    => ['General', 'heroicon-o-home'],
        'contact'    => ['Contact', 'heroicon-o-phone'],
        'social'     => ['Social links', 'heroicon-o-share'],
        'commerce'   => ['Shop', 'heroicon-o-shopping-cart'],
        'marketplace' => ['Marketplace', 'heroicon-o-building-storefront'],
        'appearance' => ['Appearance', 'heroicon-o-paint-brush'],
        'seo'        => ['SEO & tracking', 'heroicon-o-magnifying-glass'],
    ];

    public function mount(): void
    {
        $this->form->fill(
            Setting::query()->get()->mapWithKeys(fn (Setting $s) => [
                $s->key => $s->type === 'boolean'
                    ? filter_var($s->value, FILTER_VALIDATE_BOOLEAN)
                    : $s->value,
            ])->all()
        );
    }

    public function form(Schema $schema): Schema
    {
        $grouped = Setting::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        $tabs = [];

        foreach (self::GROUP_LABELS as $group => [$label, $icon]) {
            if (! $grouped->has($group)) {
                continue;
            }

            $tabs[] = Tab::make($label)
                ->icon($icon)
                ->schema([
                    Section::make()
                        ->columns(2)
                        ->schema(
                            $grouped[$group]->map(fn (Setting $s) => $this->fieldFor($s))->all()
                        ),
                ]);
        }

        // Any group added later in the database shows up automatically.
        foreach ($grouped->keys()->diff(array_keys(self::GROUP_LABELS)) as $group) {
            $tabs[] = Tab::make(Str::headline($group))
                ->schema([
                    Section::make()->columns(2)->schema(
                        $grouped[$group]->map(fn (Setting $s) => $this->fieldFor($s))->all()
                    ),
                ]);
        }

        return $schema
            ->components([Tabs::make('Settings')->tabs($tabs)->columnSpanFull()])
            ->statePath('data');
    }

    /** Build the right input for a setting based on its declared type. */
    private function fieldFor(Setting $setting)
    {
        $field = match ($setting->type) {
            'textarea' => Textarea::make($setting->key)->rows(3)->columnSpanFull(),
            'boolean'  => Toggle::make($setting->key)->inline(false),
            'number'   => TextInput::make($setting->key)->numeric(),
            'color'    => ColorPicker::make($setting->key),
            'image'    => FileUpload::make($setting->key)
                ->image()
                ->directory('settings')
                ->maxSize(2048),
            'json'     => Textarea::make($setting->key)->rows(4)->columnSpanFull(),
            default    => TextInput::make($setting->key)->maxLength(500),
        };

        return $field
            ->label($setting->label)
            ->helperText($setting->hint);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            Setting::where('key', $key)->update([
                'value' => is_bool($value) ? ($value ? '1' : '0') : $value,
            ]);
        }

        Setting::query()->first()?->touch(); // clears the settings cache via the model hook

        Notification::make()
            ->title('Settings saved')
            ->body('The storefront picks these up on its next request.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->submit('save'),
        ];
    }
}
