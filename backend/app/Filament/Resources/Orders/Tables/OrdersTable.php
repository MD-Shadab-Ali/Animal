<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Models\GoatWeight;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\PaymentService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class OrdersTable
{
    /**
     * The scale question, shared by the two places that can ask it.
     *
     * The status modal asks it on the way to Delivered; the standalone action
     * asks it while the order is still out, which is the only moment a COD
     * order can be re-priced before the money is collected.
     */

    /**
     * Write the readings onto the lines and re-price the order from them.
     *
     * Returns the signed change to the total so the caller can say which way
     * it went -- the difference is the whole reason anyone was asked.
     */
    private static function recordWeights(Order $record, array $data): float
    {
        $sameAsOrdered = (int) ($data['weight_same'] ?? 1) === 1;

        // "Yes" is a reading too: someone put the animal on a scale and it
        // matched, which is a different record from never having checked.
        $readings = $sameAsOrdered
            ? $record->weighedItems()
                ->mapWithKeys(fn (OrderItem $item) => [$item->id => (float) $item->weight_kg])
                ->all()
            : collect($data['weights'] ?? [])
                ->mapWithKeys(fn (array $row) => [(int) $row['item_id'] => (float) $row['kg']])
                ->all();

        foreach ($readings as $itemId => $kg) {
            $record->items()->whereKey($itemId)->update([
                'delivered_weight_kg' => $kg,
                'weighed_at' => now(),
                'weighed_by' => auth()->id(),
            ]);
        }

        $record->load('items');

        // The bill follows the scale. Done before any status move, so the
        // paid-in-full check guarding Delivered sees the corrected total.
        $adjustment = $record->applyWeightAdjustments();
        $record->refresh();

        $symbol = Setting::currencySymbol();
        $owed = round((float) $record->total - (float) $record->paid_amount, 2);

        Notification::make()
            ->title(abs($adjustment) < 0.01
                ? 'Weight recorded — the total is unchanged'
                : ($adjustment < 0
                    ? 'Lighter than ordered — '.$symbol.number_format(abs($adjustment), 2).' came off the total'
                    : 'Heavier than ordered — '.$symbol.number_format($adjustment, 2).' was added to the total'))
            ->body($owed > 0
                ? 'Collect '.$symbol.number_format($owed, 2).' at the door.'
                : ($owed < 0
                    ? $symbol.number_format(abs($owed), 2).' was overpaid and is owed back.'
                    : 'Nothing further to collect.'))
            ->warning()
            ->send();

        return $adjustment;
    }

    /**
     * Lines that can be filled from a pen of real animals.
     *
     * A listing keeping no individual animals, or one whose animals are all
     * sold, simply is not offered here -- the order carries on being prepared
     * the way it always was.
     */
    private static function poolableItems(Order $record)
    {
        return $record->items->filter(
            fn (OrderItem $item) => $item->goat && $item->goat->hasWeightPool()
        )->values();
    }

    /**
     * Picking which goat actually walks out of the pen.
     *
     * The nearest animal to what the buyer asked for is chosen for staff, and
     * the rest are listed either side of it so a heavier or lighter one can be
     * taken instead -- the goat nearest on paper is not always the one you
     * want to send. Nothing here touches the price: `weight_kg` remains what
     * the buyer paid for, and the difference is settled at the delivery
     * weigh-in, which is the one place that moves money.
     */
    /**
     * Tie each line to the animal staff chose, and take those animals off the
     * shelf.
     *
     * Deliberately silent about money: `weight_kg` stays what the buyer paid
     * for, and a heavier or lighter animal is settled at the delivery weigh-in.
     * Two places moving the price would be two places to get it wrong.
     */
    /**
     * The picture of whichever animal is going out, if one has been taken.
     *
     * Referenced rather than copied: the animal's own record is where that
     * photograph belongs, and two copies would drift apart the moment somebody
     * replaced one of them.
     */
    /**
     * The animals picked in the form right now, in line order.
     *
     * Read from the form rather than the order because this runs while staff
     * are still choosing -- nothing has been assigned yet, so the order still
     * names whatever it named before the modal opened.
     */
    private static function pickedAnimals(mixed $animals): Collection
    {
        $ids = collect(is_array($animals) ? $animals : [])
            ->pluck('animal_id')
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $found = GoatWeight::whereIn('id', $ids)->get()->keyBy('id');

        // Back into line order: whereIn returns them however the database
        // felt like, and the first line's animal is the one whose photo is
        // used, so the order here is the answer rather than presentation.
        return $ids->map(fn ($id) => $found->get($id))->filter()->values();
    }

    /**
     * What the buyer will see, or why they will see nothing.
     *
     * Deliberately mirrors assignedAnimalPhoto(): first line first, first
     * photograph found wins. If the two ever disagree the preview becomes a
     * lie, which is worse than no preview.
     */
    private static function animalPhotoPreview(mixed $animals): HtmlString
    {
        $picked = self::pickedAnimals($animals);
        $withPhoto = $picked->first(fn (GoatWeight $animal) => filled($animal->image));

        $note = fn (string $text) => new HtmlString(
            '<p style="margin:0;font-size:.875rem;opacity:.7">'.e($text).'</p>'
        );

        if ($picked->isEmpty()) {
            return $note('Choose the animal below and its photograph appears here.');
        }

        if (! $withPhoto) {
            return $note($picked->first()->label().' has no photograph on file. '
                .'Upload one below, or add it to the animal so it is there next time.');
        }

        return new HtmlString(sprintf(
            '<div style="display:flex;align-items:center;gap:.75rem">'
                .'<img src="%s" alt="%s" style="width:5rem;height:5rem;object-fit:cover;'
                .'border-radius:.5rem;border:1px solid rgba(128,128,128,.3)">'
                .'<div><div style="font-weight:600">%s</div>'
                .'<p style="margin:0;font-size:.875rem;opacity:.7">%s</p></div></div>',
            e($withPhoto->image_url),
            e($withPhoto->label()),
            e($withPhoto->label()),
            e('This is the photograph the buyer will see.')
        ));
    }

    private static function assignedAnimalPhoto(Order $record): ?string
    {
        return $record->items()
            ->with('goatWeight')
            ->get()
            ->map(fn (OrderItem $item) => $item->goatWeight?->image)
            ->filter()
            ->first();
    }

    private static function assignAnimals(Order $record, array $rows): void
    {
        foreach ($rows as $row) {
            $item = $record->items->firstWhere('id', $row['item_id'] ?? null);

            if (! $item) {
                continue;
            }

            $animal = $row['animal_id'] ? GoatWeight::find($row['animal_id']) : null;

            // Only ever an animal belonging to the goat on this line: a stale
            // form or a hand-edited request must not attach somebody else's.
            if ($animal && $animal->goat_id !== $item->goat_id) {
                continue;
            }

            $item->assignAnimal($animal);
        }
    }

    private static function animalSchema(): array
    {
        return [
            Repeater::make('animals')
                ->label('Which animal is going out')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->visible(fn (Get $get, Order $record): bool => $get('status') === 'processing'
                    && self::poolableItems($record)->isNotEmpty())
                ->default(fn (Order $record): array => self::poolableItems($record)
                    ->map(fn (OrderItem $item) => [
                        'item_id' => $item->id,
                        'summary' => $item->goat_name.' — ordered at '.$item->weight_kg.' kg',
                        // Pre-picked, so the common case is one glance and Submit.
                        // An animal already assigned stays assigned rather than
                        // being silently swapped for whatever is nearest now.
                        'animal_id' => $item->goat_weight_id
                            ?: $item->goat->nearestWeight((float) $item->weight_kg)?->id,
                    ])
                    ->all())
                ->schema([
                    Hidden::make('item_id'),
                    Hidden::make('summary'),

                    // Named apart from the field it reads. A Placeholder whose
                    // content asks for its own name calls itself, and PHP dies
                    // somewhere past a million frames.
                    Placeholder::make('ordered_line')
                        ->label('Goat')
                        ->content(fn (Get $get): string => (string) $get('summary')),

                    Select::make('animal_id')
                        ->label('Animal from the farm')
                        ->native(false)
                        ->options(function (Get $get): array {
                            $item = OrderItem::with('goat')->find($get('item_id'));

                            if (! $item?->goat) {
                                return [];
                            }

                            $ordered = (float) $item->weight_kg;

                            // Whatever is already on this line stays choosable
                            // even though it is no longer available, or the
                            // form would blank out its own default.
                            $pool = $item->goat->availableWeights();

                            if ($item->goatWeight && ! $pool->contains('id', $item->goat_weight_id)) {
                                $pool = $pool->push($item->goatWeight);
                            }

                            return $pool
                                ->sortBy(fn (GoatWeight $animal) => abs((float) $animal->weight_kg - $ordered))
                                ->mapWithKeys(function (GoatWeight $animal) use ($ordered) {
                                    $kg = (float) $animal->weight_kg;
                                    $gap = round($kg - $ordered, 2);

                                    $how = match (true) {
                                        abs($gap) < 0.005 => 'exact match',
                                        $gap > 0 => '+'.abs($gap).' kg heavier',
                                        default => abs($gap).' kg lighter',
                                    };

                                    return [$animal->id => trim(($animal->tag ? $animal->tag.' · ' : '')
                                        .$kg.' kg · '.$how)];
                                })
                                ->all();
                        })
                        // The preview above reads this, so a change to it has
                        // to reach the server or the picture would go on
                        // showing the animal that was swapped out.
                        ->live()
                        ->helperText('Nearest to what the buyer ordered is chosen already. '
                            .'The list runs outwards from it, closest first.'),
                ]),
        ];
    }

    private static function weightSchema(): array
    {
        return [
            Radio::make('weight_same')
                ->label('Is the goat weight the same as ordered?')
                ->options([1 => 'Yes', 0 => 'No'])
                ->inline()
                ->inlineLabel(false)
                ->default(1)
                ->live()
                ->required(),

            Radio::make('weight_direction')
                ->label('What changed?')
                ->options(['increased' => 'Weight increased', 'decreased' => 'Weight decreased'])
                ->inline()
                ->inlineLabel(false)
                ->live()
                ->required()
                ->visible(fn (Get $get): bool => (int) $get('weight_same') === 0),

            Repeater::make('weights')
                ->label('Weight on the scale')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->default(fn (Order $record): array => $record->weighedItems()
                    ->map(fn (OrderItem $item) => [
                        'item_id' => $item->id,
                        'goat_name' => $item->goat_name,
                        'ordered' => (float) $item->weight_kg,
                        'kg' => null,
                    ])
                    ->all())
                ->schema([
                    Hidden::make('item_id'),
                    Hidden::make('ordered'),
                    Hidden::make('goat_name'),

                    Placeholder::make('ordered_summary')
                        ->label('Goat')
                        ->content(fn (Get $get): string => $get('goat_name')
                            .' — ordered at '.$get('ordered').' kg'),

                    TextInput::make('kg')
                        ->label('Weight at delivery')
                        ->numeric()
                        ->required()
                        ->minValue(0.1)
                        ->maxValue(500)
                        ->suffix('kg')
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                $ordered = (float) $get('ordered');
                                $direction = $get('../../weight_direction');
                                $actual = (float) $value;

                                if ($direction === 'increased' && $actual <= $ordered) {
                                    $fail('You chose increased, so this has to be more than '.$ordered.' kg.');
                                }

                                if ($direction === 'decreased' && $actual >= $ordered) {
                                    $fail('You chose decreased, so this has to be less than '.$ordered.' kg.');
                                }
                            },
                        ]),
                ])
                ->visible(fn (Get $get): bool => (int) $get('weight_same') === 0),
        ];
    }

    public static function configure(Table $table): Table
    {
        $currency = Setting::currencyCode();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->copyable(),

                TextColumn::make('customer_name')
                    ->searchable()
                    ->description(fn (Order $record) => $record->customer_phone),

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total')
                    ->money($currency)
                    ->sortable(),

                TextColumn::make('payment_method')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => strtoupper((string) $state))
                    ->toggleable(),

                TextColumn::make('payment_plan')
                    ->label('Plan')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'full' => 'Paid up front',
                        'advance' => 'Advance',
                        default => 'On delivery',
                    })
                    ->toggleable(),

                // Money the shop is holding that the order no longer asks for.
                // Nothing else on this screen would say so: the order is live,
                // paid, and on its way -- it just costs less than it did.
                TextColumn::make('overpaid_amount')
                    ->label('Owed back')
                    ->badge()
                    ->color('danger')
                    ->placeholder('')
                    ->state(fn (Order $record) => $record->overpaid_amount > 0
                        ? Setting::currencySymbol()
                            .number_format($record->overpaid_amount, 2)
                        : null)
                    ->tooltip('Weighed lighter than ordered — this is refundable'),

                TextColumn::make('payment_status')
                    ->badge()
                    ->colors([
                        'danger' => 'unpaid',
                        'warning' => 'partially_paid',
                        'success' => 'paid',
                        'gray' => 'refunded',
                    ]),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Order::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => Order::STATUS_COLORS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('run_by')
                    ->label('Run by')
                    ->state(fn (Order $record) => $record->isSellerManaged()
                        ? ($record->items->first()?->seller_name ?? 'Seller')
                        : 'Our team')
                    ->badge()
                    ->color(fn (Order $record) => $record->isSellerManaged() ? 'info' : 'gray')
                    ->description(fn (Order $record) => $record->isSellerManaged()
                        ? 'seller-supplied'
                        : null),

                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime('d M Y, g:i a')
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),

                SelectFilter::make('status')
                    ->options(Order::STATUSES)
                    ->multiple(),

                SelectFilter::make('payment_status')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'partially_paid' => 'Partially paid',
                        'paid' => 'Paid',
                        'refunded' => 'Refunded',
                    ]),

                SelectFilter::make('delivery_zone_id')
                    ->label('Delivery zone')
                    ->relationship('deliveryZone', 'name'),

                Filter::make('today')
                    ->label('Placed today')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ])
            ->recordActions([
                RestoreAction::make(),
                ForceDeleteAction::make(),
                ViewAction::make(),

                Action::make('updateStatus')
                    ->label('Update status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    // Staff and the seller both run seller-supplied orders, so
                    // the control stays available on every order -- but only
                    // while there is a step left to take.
                    ->visible(fn (Order $record): bool => $record->nextStatus() !== null
                        || $record->isCancellable())
                    ->schema([
                        Select::make('status')
                            ->label('New status')
                            // One step, in order. Picking a status out of the
                            // middle skipped the states either side of it: an
                            // order could reach Out for delivery having never
                            // been Confirmed, leaving the buyer's timeline and
                            // the seller's own screens describing a sequence
                            // that never happened.
                            // Every status is listed, so the whole run is
                            // visible and staff can see where an order sits in
                            // it. Only the reachable ones can be picked.
                            ->options(Order::STATUSES)
                            ->disableOptionWhen(function (string $value, Order $record): bool {
                                // Cancelling is not a step in the sequence: an
                                // order can fall over at any point, so it stays
                                // clickable until the animal is handed over.
                                if ($value === 'cancelled') {
                                    return ! $record->isCancellable();
                                }

                                // One step, in order. Anything else would let an
                                // order reach Out for delivery having never been
                                // Confirmed or Prepared.
                                if ($value !== $record->nextStatus()) {
                                    return true;
                                }

                                // Delivered means paid for. Recording the money
                                // closes the order by itself.
                                return $value === 'delivered' && ! $record->canBeDelivered();
                            })
                            ->required()
                            ->native(false)
                            // Live so the photo field below can appear the
                            // moment Preparing is chosen.
                            ->live()
                            // Delivered means paid for. The order closes itself
                            // once the money is recorded, so this is a nudge to
                            // the Payments tab rather than a second way in.
                            ->helperText(fn (Order $record) => $record->nextStatus() === 'delivered'
                                && ! $record->canBeDelivered()
                                    ? 'This order is not paid for yet, so it cannot be delivered. '
                                        .'Record the payment and it will close itself.'
                                    : null)
                            // Only ever defaults to something actually
                            // selectable. Pre-filling Delivered on an unpaid
                            // order put a disabled value in the box and failed
                            // validation the moment Submit was pressed.
                            ->default(function (Order $record): ?string {
                                $next = $record->nextStatus();

                                if ($next === 'delivered' && ! $record->canBeDelivered()) {
                                    return null;
                                }

                                return $next;
                            }),
                        Textarea::make('note')
                            ->label('Note (optional)')
                            ->rows(2)
                            ->helperText('The buyer sees this on their order, so write it to them.'),

                        // Preparing only. That is the step where the buyer
                        // loses sight of what they bought -- the listing photo
                        // was taken before they ordered, and on a listing sold
                        // by weight it may not even be the animal they are
                        // getting. The other steps say where the order is, not
                        // what the animal looks like.
                        /*
                         * The photograph that is going to be used, shown.
                         *
                         * Staff were being handed an empty dropzone and told in
                         * words that a photo they could not see would be used
                         * instead. Showing it is the difference between trusting
                         * the sentence and checking the animal.
                         *
                         * Named apart from every field it reads: a Placeholder
                         * whose content asks for its own name calls itself.
                         */
                        Placeholder::make('animal_photo')
                            ->label('Photo of the animal')
                            ->visible(fn (Get $get, Order $record): bool => $get('status') === 'processing'
                                && self::poolableItems($record)->isNotEmpty())
                            ->content(fn (Get $get): HtmlString => self::animalPhotoPreview($get('animals'))),

                        FileUpload::make('photo')
                            // Reads as the override it is once the picture
                            // above is on screen, and as the only photo going
                            // when this listing keeps no animals to pull one from.
                            ->label(fn (Order $record): string => self::poolableItems($record)->isNotEmpty()
                                ? 'Use a different photo (optional)'
                                : 'Photo of the animal (optional)')
                            ->image()
                            ->imageEditor()
                            ->directory('order-status')
                            ->maxSize(4096)
                            ->visible(fn (callable $get): bool => $get('status') === 'processing')
                            ->helperText(fn (Order $record): ?string => self::poolableItems($record)->isNotEmpty()
                                ? 'Anything uploaded here is shown to the buyer instead of the one above.'
                                : null),

                        // Preparing is when someone walks out to the pen, so it
                        // is when the animal gets chosen.
                        ...self::animalSchema(),
                    ])
                    ->action(function (Order $record, array $data): void {
                        // Recorded before the status moves, so a line is never
                        // marked delivered with no idea what turned up.
                        // The scale question moved to its own action: cash on
                        // delivery closes the order the moment it is paid, so an
                        // order is re-priced before Delivered is ever reached.
                        // Anything still unweighed by the time staff close it
                        // manually is recorded here as matching the order.
                        if (($data['status'] ?? null) === 'delivered' && $record->hasWeighedItems()) {
                            $unweighed = $record->weighedItems()
                                ->every(fn (OrderItem $item) => ! $item->was_weighed);

                            if ($unweighed) {
                                self::recordWeights($record, ['weight_same' => 1]);
                            }
                        }

                        // Which goat is actually going out. Done before the
                        // status moves so the buyer's order already names the
                        // animal by the time Preparing shows on their timeline.
                        if (($data['status'] ?? null) === 'processing') {
                            self::assignAnimals($record, $data['animals'] ?? []);
                        }

                        // Handed to the observer, which writes them onto the
                        // status-history row this update is about to create.
                        $record->statusNote = $data['note'] ?: null;

                        // Guarded as well as hidden: a photo picked before the
                        // status was changed away from Preparing must not ride
                        // along on a step it does not belong to.
                        /*
                         * The animal already has its picture taken, so asking
                         * staff to photograph it again at Preparing was asking
                         * twice for the same thing. An upload here still wins,
                         * for when a better shot is wanted.
                         */
                        $record->statusPhoto = $data['status'] === 'processing'
                            ? (($data['photo'] ?? null) ?: self::assignedAnimalPhoto($record))
                            : null;

                        $record->update([
                            'status' => $data['status'],
                            // Kept as the running internal note as well, which is
                            // what this field did before it was ever shown to
                            // anyone outside the admin.
                            'admin_note' => $data['note'] ?: $record->admin_note,
                        ]);

                        Notification::make()
                            ->title('Order '.$record->order_number.' is now '.($record->status_label))
                            ->success()
                            ->send();
                    }),

                /*
                 * Weighing before the money changes hands.
                 *
                 * Cash on delivery is collected at the door and the order
                 * closes itself once it is paid in full, so on a COD order the
                 * buyer is handed a bill before anyone reaches the Delivered
                 * step. The weight has to be recordable while the order is
                 * still out, or the amount collected is the one from before
                 * the goat travelled.
                 */
                Action::make('recordWeight')
                    ->label('Record delivery weight')
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->visible(fn (Order $record): bool => $record->status === 'out_for_delivery'
                        && $record->hasWeighedItems()
                        && $record->weighedItems()->every(fn (OrderItem $item) => ! $item->was_weighed))
                    ->schema(self::weightSchema())
                    ->action(fn (Order $record, array $data) => self::recordWeights($record, $data)),

                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->payment_status !== 'paid'
                        && $record->status !== 'cancelled')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Amount received')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->default(fn (Order $record) => $record->balance_due)
                            ->helperText(fn (Order $record) => 'Outstanding balance: '
                                .number_format($record->balance_due, 2)),
                        TextInput::make('transaction_id')
                            ->label('Reference (optional)'),
                    ])
                    // Through the ledger, never straight onto the order: the
                    // order's paid_amount is derived from the payments, so a
                    // direct write here would be silently undone by the next sync.
                    ->action(function (Order $record, array $data): void {
                        $payment = app(PaymentService::class)->record($record, [
                            'amount' => $data['amount'],
                            'method' => $record->payment_method,
                            'transaction_reference' => $data['transaction_id'] ?: null,
                        ], auth()->user());

                        $record->refresh();

                        Notification::make()
                            ->title('Payment '.$payment->reference.' recorded')
                            ->body($record->status === 'delivered'
                                ? $record->order_number.' is settled and now marked delivered.'
                                : 'Outstanding: '.number_format($record->balance_due, 2))
                            ->success()
                            ->send();
                    }),

                // Kept on every order, including seller-run ones: without it a
                // disputed or fraudulent order could never be resolved.
                Action::make('cancelOrder')
                    ->label('Cancel order')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Order $record) => $record->status !== 'cancelled'
                        && $record->status !== 'delivered')
                    ->requiresConfirmation()
                    ->modalDescription('The goats go back on sale and the seller is told. Use this for disputes, not routine progress.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Why are you cancelling?')
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (Order $record, array $data): void {
                        $record->update([
                            'status' => 'cancelled',
                            'admin_note' => trim($record->admin_note.'
'.'Cancelled by staff: '.$data['reason']),
                        ]);

                        Notification::make()
                            ->title($record->order_number.' cancelled')
                            ->body('Stock has been released.')
                            ->warning()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
