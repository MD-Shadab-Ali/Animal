<?php

namespace App\Filament\Resources\Sellers\Schemas;

use App\Models\PaymentMethod;
use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SellerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vetting')
                ->description('Only approved sellers can list, and their goats leave the shop the moment they are suspended.')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->options([
                            'pending'   => 'Pending review',
                            'approved'  => 'Approved — can sell',
                            'suspended' => 'Suspended — listings hidden',
                            'rejected'  => 'Rejected',
                        ])
                        ->required()
                        ->native(false),

                    TextInput::make('commission_rate')
                        ->label('Commission rate')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->placeholder(fn () => Setting::get('default_commission_rate', 10))
                        ->helperText('Leave blank to use the platform default.'),

                    Textarea::make('review_note')
                        ->label('Note to the seller')
                        ->rows(2)
                        ->columnSpanFull()
                        ->helperText('Sent with rejection and suspension emails.'),
                ]),

            Section::make('Farm')
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Account')
                        ->relationship('user', 'name')
                        ->searchable(['name', 'email'])
                        ->preload()
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('farm_name')->required()->maxLength(255),
                    TextInput::make('slug')->unique(ignoreRecord: true)->maxLength(255),
                    TextInput::make('contact_phone')->tel()->required()->maxLength(30),
                    TextInput::make('contact_email')->email()->maxLength(255),

                    Textarea::make('bio')->rows(3)->columnSpanFull(),
                ]),

            Section::make('Location')
                ->columns(3)
                ->schema([
                    TextInput::make('address_line')->columnSpan(2),
                    TextInput::make('area'),
                    TextInput::make('city')->required(),
                    TextInput::make('postal_code'),
                ]),

            Section::make('Identity documents')
                ->description('What the seller submitted with their application. Check these before approving.')
                ->columns(2)
                ->schema([
                    TextInput::make('national_id')
                        ->label('National ID number')
                        ->columnSpanFull()
                        ->placeholder('Not provided'),

                    FileUpload::make('id_document')
                        ->label('ID document')
                        ->directory('sellers/documents')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->openable()
                        ->downloadable()
                        ->helperText('Required from every seller. Click to open it full size.'),

                    FileUpload::make('trade_licence')
                        ->label('Trade licence')
                        ->directory('sellers/documents')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                        ->maxSize(5120)
                        ->openable()
                        ->downloadable()
                        ->helperText('Optional — many smallholders do not have one.'),
                ]),

            Section::make('Payout details')
                ->collapsed()
                ->columns(3)
                ->schema([
                    Select::make('payout_method')
                        ->label('Payout method')
                        // The list is whatever Configuration -> Payment methods
                        // marks as a payout rail, so this never drifts from what
                        // the seller sees on their earnings page.
                        ->options(fn () => PaymentMethod::payout()
                            ->orderBy('sort_order')
                            ->pluck('name', 'code'))
                        ->helperText('Only methods switched on for payouts appear here.')
                        ->native(false)
                        ->searchable()
                        ->live(),
                    TextInput::make('payout_bank_name')
                        ->label('Bank name')
                        // Wallets have no bank, so the field only appears for the
                        // methods that were marked as needing one.
                        ->visible(fn ($get) => (bool) PaymentMethod::where('code', $get('payout_method'))
                            ->value('requires_bank_name')),
                    TextInput::make('payout_account_name'),
                    TextInput::make('payout_account_number'),
                ]),
        ]);
    }
}
