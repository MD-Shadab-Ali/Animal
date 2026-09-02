<?php

namespace App\Filament\Resources\Bookings;

use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Bookings\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Bookings\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Bookings\Schemas\BookingForm;
use App\Filament\Resources\Bookings\Tables\BookingsTable;
use App\Models\Booking;
use App\Support\RestrictsAccessByRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class BookingResource extends Resource
{
    use RestrictsAccessByRole;

    /**
     * Sales, for the same reason a room is catalogue.
     *
     * A booking is money owed and a person arriving, which is what the sales
     * area already means -- and `Staff` hold it, which is right: the people who
     * hand over a key are the people who need this screen.
     */
    protected static string $area = 'sales';

    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Homestay';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'booking_number';

    public static function form(Schema $schema): Schema
    {
        return BookingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            StatusHistoriesRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([
            SoftDeletingScope::class,
        ]);
    }

    /**
     * Stays waiting on somebody here.
     *
     * Placed bookings, because a room is being held for money that has not
     * arrived, and anybody due to arrive today, because a bed has to be made
     * up. Both are things the farm has to act on. A confirmed stay three weeks
     * out is not, and counting it would make the badge furniture.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = static::getModel()::where('status', 'placed')->count()
            + static::getModel()::where('status', 'confirmed')
                ->whereDate('check_in', today())
                ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['booking_number', 'guest_name', 'guest_phone', 'room_name'];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBookings::route('/'),
            'create' => CreateBooking::route('/create'),
            'view'   => ViewBooking::route('/{record}'),
            'edit'   => EditBooking::route('/{record}/edit'),
        ];
    }
}
