<?php

namespace App\Filament\Resources\Inquiries\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class InquiryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('goat_id')
                    ->relationship('goat', 'name'),
                Select::make('user_id')
                    ->relationship('user', 'name'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('phone')
                    ->tel()
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                Textarea::make('message')
                    ->columnSpanFull(),
                Select::make('status')
                    ->options(['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'])
                    ->default('new')
                    ->required(),
            ]);
    }
}
