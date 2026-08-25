<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Account')->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('phone')
                    ->tel()
                    ->maxLength(30),

                Select::make('role')
                    ->options(fn (): array => auth()->user()?->isAdmin()
                        ? UserRole::options()
                        : [UserRole::Customer->value => UserRole::Customer->label()])
                    ->default(UserRole::Customer->value)
                    ->required()
                    ->native(false)
                    // Only an administrator can hand out panel access.
                    ->disabled(fn (): bool => ! (auth()->user()?->isAdmin() ?? false))
                    ->dehydrated()
                    ->helperText(fn (): ?string => auth()->user()?->isAdmin()
                        ? 'Administrator, Manager and Staff can all sign in to this panel.'
                        : 'Only an administrator can change roles.'),

                FileUpload::make('avatar')
                    ->image()
                    ->avatar()
                    ->directory('avatars')
                    ->maxSize(2048),

                Toggle::make('is_active')
                    ->label('Account active')
                    ->default(true)
                    ->inline(false)
                    ->helperText('Switching this off blocks sign-in.'),
            ]),

            Section::make('Password')
                ->description('Leave blank to keep the current password.')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->rule(Password::min(8))
                        // Only required when creating; blank on edit means "unchanged".
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->autocomplete('new-password'),
                ]),
        ]);
    }
}
