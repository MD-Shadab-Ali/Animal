<?php

namespace App\Filament\Resources\PaymentMethods\Tables;

use App\Models\PaymentMethod;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('logo')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Checkout')
                    ->boolean(),

                // Without an account on file a buyer has nowhere to send money,
                // so the "pay now" panel cannot appear. Say which ones are set.
                IconColumn::make('on_delivery_only')
                    ->label('Delivery only')
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('payee_account_number')
                    ->label('Buyers can pay')
                    ->badge()
                    ->state(fn (PaymentMethod $record) => match (true) {
                        ! $record->is_active          => 'Off',
                        $record->on_delivery_only     => 'On delivery',
                        $record->isPrepayable()       => 'Yes',
                        default                       => 'No account set',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'Yes'           => 'success',
                        'No account set' => 'danger',
                        default         => 'gray',
                    }),
                IconColumn::make('supports_payout')
                    ->label('Payouts')
                    ->boolean(),
                IconColumn::make('requires_bank_name')
                    ->label('Needs bank name')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('requires_advance')
                    ->label('Up front')
                    ->boolean(),

                // Reads as "30%" or "Rs 5,000.00" — the raw number alone never
                // said which of the two it was.
                TextColumn::make('advance_amount')
                    ->label('Advance')
                    ->state(fn (PaymentMethod $record) => $record->advance_label)
                    ->placeholder('—'),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
