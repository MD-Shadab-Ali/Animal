<?php

namespace App\Filament\Resources\Goats\RelationManagers;

use App\Models\GoatWeight;
use App\Models\Setting;
use App\Services\GoatQrArchive;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The actual goats behind this listing.
 *
 * Filling this in changes what the buyer's weight selector offers: instead of
 * every number between the listing's minimum and maximum, it offers only these
 * weights, and a buyer asking for something in between is given the nearest of
 * them. Leave it empty and the listing prices any weight in its range as
 * before, settling up on the scale at delivery.
 */
class WeightsRelationManager extends RelationManager
{
    protected static string $relationship = 'weights';

    protected static ?string $title = 'Weights on the farm';

    protected static string|BackedEnum|null $icon = 'heroicon-o-scale';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('weight_kg')
                ->label('Weight (kg)')
                ->helperText('What this animal actually weighed on the scale.')
                ->numeric()
                ->minValue(1)
                ->step(0.01)
                ->required(),

            TextInput::make('tag')
                ->label('Ear tag or pen number')
                ->helperText('Optional, but it is how you tell two goats of the same weight apart.')
                ->maxLength(60),

            /*
             * This animal's own age, dentition and veterinary record.
             *
             * They used to sit on the listing, where a single value had to
             * stand for every goat behind it -- and behind one listing are
             * fifteen animals decades of goat-years apart. Left empty they read
             * as "not recorded", which is the honest state for a reading nobody
             * has taken, and is not the same as zero or no.
             */
            TextInput::make('age_months')
                ->label('Age (months)')
                ->helperText('This goat, not the listing.')
                ->numeric()
                ->minValue(0)
                ->maxValue(240),

            Select::make('teeth')
                ->label('Permanent incisors')
                ->helperText('How age is actually read at the gate.')
                ->options([
                    0 => 'Milk teeth — under a year',
                    2 => '2 — about 1 to 1½ years',
                    4 => '4 — about 2 years',
                    6 => '6 — about 2 to 3 years',
                    8 => 'Full mouth — 3 years or more',
                ]),

            TextInput::make('color')
                ->label('Colour and markings')
                ->maxLength(120),

            TextInput::make('health_status')
                ->label('Health')
                ->placeholder('Vet checked — healthy')
                ->maxLength(160),

            Select::make('is_vaccinated')
                ->label('Vaccinated')
                ->helperText('Leave blank until someone has actually checked.')
                ->options([1 => 'Yes', 0 => 'No'])
                ->native(false),

            DatePicker::make('vet_checked_at')
                ->label('Vet checked on')
                ->maxDate(now()),

            DatePicker::make('dewormed_at')
                ->label('Dewormed on')
                ->maxDate(now()),

            FileUpload::make('image')
                ->label('Photo of this goat')
                ->helperText('The animal itself, not the breed. Buyers see it once this '
                    .'is the weight they have landed on. Leave it empty and the '
                    .'photos from the listing are shown instead.')
                ->image()
                ->imageEditor()
                ->directory('goats/animals')
                ->maxSize(4096)
                ->columnSpanFull(),

            /*
             * The tag itself.
             *
             * Rendered inline as an SVG data URI rather than saved as a file:
             * there is nothing to clean up when an animal is deleted, and the
             * code is always drawn from the token as it stands rather than
             * from an image that could outlive it.
             */
            Placeholder::make('qr')
                ->label('Tag to scan')
                ->content(fn ($record) => $record
                    ? new HtmlString(
                        '<div style="display:flex;gap:.75rem;align-items:flex-start;flex-wrap:wrap">'
                        .'<img src="'.$record->qrDataUri(150).'" alt="QR code for this goat" '
                        .'width="150" height="150" style="background:#fff;padding:6px;border-radius:6px" />'
                        .'<a href="'.e($record->publicUrl()).'" target="_blank" rel="noopener" '
                        .'style="word-break:break-all;font-size:.75rem;line-height:1.4;opacity:.75">'
                        .e($record->publicUrl()).'</a>'
                        .'</div>'
                    )
                    : 'Save this animal and its code appears here.')
                ->helperText('Print it and put it on the pen. Scanning shows this goat: its '
                    .'photo, weight, tag and state. Anyone can open it, which is the point -- '
                    .'a buyer at the gate can check the animal is the one they paid for.'),

            Textarea::make('notes')
                ->label('Anything else about this goat')
                ->rows(2)
                ->columnSpanFull(),

            Select::make('status')
                ->label('State')
                ->options([
                    'available' => 'Available',
                    'sold' => 'Sold',
                ])
                ->default('available')
                ->helperText('Sold animals drop off the buyer\'s selector.')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('weight_kg')
            ->defaultSort('weight_kg')
            ->description('Only these weights are offered to buyers. Someone asking for a '
                .'weight in between is given the nearest one, and the price follows it. '
                .'Leave this empty to price any weight in the listing\'s range instead.')
            ->columns([
                ImageColumn::make('image')
                    ->label('Photo')
                    ->square()
                    ->defaultImageUrl(fn ($record) => $record->goat?->thumbnail_url),

                TextColumn::make('weight_kg')
                    ->label('Weight')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').' kg')
                    ->sortable(),

                TextColumn::make('tag')->label('Tag')->placeholder('—')->searchable(),

                // Shown so the gaps are obvious: an animal with no age
                // recorded is one whose scanned page has little to say.
                TextColumn::make('age_months')
                    ->label('Age')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : $state.' mo')
                    ->placeholder('not recorded')
                    ->sortable(),

                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn ($record) => $record->price())
                    ->money(fn () => Setting::get('currency', 'NPR'))
                    ->description('At the listing rate'),

                TextColumn::make('status')
                    ->label('State')
                    ->badge()
                    ->color(fn (string $state) => $state === 'available' ? 'success' : 'gray'),

                TextColumn::make('sold_at')->label('Sold')->dateTime('d M Y')->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add a goat')
                    ->modalHeading('Add an animal to this listing'),

                /*
                 * The whole pen's tags at once, which is how they are printed.
                 * Hidden while the listing has no animals rather than shown
                 * and failing: an empty zip is not a file anybody can open.
                 */
                Action::make('downloadAllQr')
                    ->label('Download all QR codes')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->weights()->exists())
                    ->action(fn (): StreamedResponse => $this->downloadQrArchive(
                        $this->getOwnerRecord()->weights()->orderBy('weight_kg')->get()
                    )),
            ])
            ->recordActions([
                // Sits with the row, because the tag belongs to this animal:
                // whoever is looking at the 17.5 kg goat is the one who wants
                // its code, and reopening the edit form to reach it is a step
                // that earns nothing.
                Action::make('downloadQr')
                    ->label('QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->tooltip(fn (GoatWeight $record): string => 'Download '.$record->qrFileName())
                    ->action(function (GoatWeight $record): StreamedResponse {
                        $jpeg = $record->qrJpeg(512);

                        return response()->streamDownload(
                            function () use ($jpeg) {
                                echo $jpeg;
                            },
                            $record->qrFileName(),
                            ['Content-Type' => 'image/jpeg'],
                        );
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('downloadQr')
                        ->label('Download QR codes')
                        ->icon('heroicon-o-qr-code')
                        ->color('gray')
                        ->action(fn (Collection $records): StreamedResponse => $this->downloadQrArchive($records))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No individual goats listed')
            ->emptyStateDescription('Add them and the buyer picks from real weights. '
                .'Until then this listing prices any weight in its range.');
    }

    /**
     * These animals' codes, zipped and named after the listing they are under.
     *
     * The owner record is pushed onto every row before the names are worked
     * out: each file is called after the listing, and reading that off the
     * relationship one row at a time would be a query per goat for a name this
     * page already has.
     *
     * @param  Collection<int, GoatWeight>  $weights
     */
    private function downloadQrArchive(Collection $weights): StreamedResponse
    {
        $listing = $this->getOwnerRecord();

        $weights->each(fn (GoatWeight $weight) => $weight->setRelation('goat', $listing));

        $archive = app(GoatQrArchive::class);
        $path = $archive->write($weights);

        return response()->streamDownload(
            function () use ($path) {
                readfile($path);
                @unlink($path);
            },
            $archive->fileName($listing),
            ['Content-Type' => 'application/zip'],
        );
    }
}
