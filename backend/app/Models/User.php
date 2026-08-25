<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'role', 'phone', 'avatar', 'is_active', 'password',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'role'              => UserRole::class,
        ];
    }

    /** Only staff accounts may open the Filament panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && in_array($this->role, UserRole::staffRoles(), true);
    }

    /** May this account open the given area of the admin panel? */
    public function canManage(string $area): bool
    {
        return $this->is_active && in_array($area, $this->role->areas(), true);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, UserRole::staffRoles(), true);
    }

    /** Send the reset link that points at the storefront, not this API. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Everyone who should receive operational alerts (new orders, low stock). */
    public static function staffRecipients()
    {
        return static::query()
            ->whereIn('role', array_column(UserRole::staffRoles(), 'value'))
            ->where('is_active', true)
            ->get();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    /** Can this account list goats for sale right now? */
    public function isSeller(): bool
    {
        return $this->seller?->isApproved() ?? false;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('is_default', true);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? asset('storage/'.$this->avatar) : null;
    }
}
