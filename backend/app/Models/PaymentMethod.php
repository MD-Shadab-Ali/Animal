<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    /**
     * Methods a provider can be asked about after the fact.
     *
     * Cash on delivery and bank transfer are absent on purpose: nothing calls
     * us when cash changes hands or a transfer lands, so those stay manual.
     */
    public const GATEWAY_CODES = ['esewa', 'khalti'];

    public function isGateway(): bool
    {
        return in_array($this->code, self::GATEWAY_CODES, true);
    }

    protected $fillable = [
        'code', 'name', 'instructions', 'logo', 'is_active', 'on_delivery_only',
        'supports_payout', 'requires_bank_name',
        'payee_account_name', 'payee_account_number', 'payee_bank_name', 'payee_qr_image',
        'refund_eta',
        'requires_advance', 'advance_amount', 'advance_type', 'config', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
            'on_delivery_only' => 'boolean',
            'supports_payout' => 'boolean',
            'requires_bank_name' => 'boolean',
            'requires_advance' => 'boolean',
            'advance_amount' => 'decimal:2',
        ];
    }

    protected $hidden = ['config'];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * A buyer may start an order with this.
     *
     * Delivery-only methods stay active — staff still record cash against them
     * — they just cannot be what an order is placed on.
     *
     * With one exception, and it matters: a shop must always have some way to
     * take an order. If nothing else is switched on, a delivery-only method
     * stands in rather than leaving the checkout with no option at all — which
     * is exactly the state a fresh install ships in, where cash on delivery is
     * the only active method.
     */
    public function isCheckoutSelectable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        /*
         * A gateway with no credentials cannot open a payment at all, so
         * offering it only produces an order nobody can pay for -- which is
         * exactly what it did: the buyer got as far as the order page and met
         * a payment that could never start.
         */
        if ($this->isGateway() && ! $this->isGatewayConfigured()) {
            return false;
        }

        return ! $this->on_delivery_only || ! static::hasAnyCheckoutMethod();
    }

    /** Is there anything a buyer can actually place an order with? */
    public static function hasAnyCheckoutMethod(): bool
    {
        // Filtered in PHP rather than SQL: whether a gateway is usable lives in
        // the environment, not in a column. Cash on delivery falls back to
        // being selectable when this is false, so an unconfigured gateway must
        // not count as a way to place an order.
        return static::query()
            ->where('is_active', true)
            ->where('on_delivery_only', false)
            ->get()
            ->contains(fn (self $method) => ! $method->isGateway() || $method->isGatewayConfigured());
    }

    /** Why it is greyed out at checkout, or null when it is not. */
    public function checkoutUnavailableReason(): ?string
    {
        if ($this->isCheckoutSelectable()) {
            return null;
        }

        if ($this->isGateway() && ! $this->isGatewayConfigured()) {
            return $this->name.' is not available at the moment. Please choose another way to pay.';
        }

        return 'Not a way to place an order. Choose how to pay up front below, and settle '
            .'whatever is left in cash when your goat is delivered.';
    }

    /** Methods a seller can actually be paid out through. */
    public function scopePayout(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('supports_payout', true);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    public function getPayeeQrUrlAttribute(): ?string
    {
        return $this->payee_qr_image ? asset('storage/'.$this->payee_qr_image) : null;
    }

    /**
     * The buyer can send money to this before delivery.
     *
     * Decided by whether an admin has actually filled in an account to send to
     * — cash on delivery has none, so it never offers a "pay now" panel.
     */
    public function isPrepayable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        /*
         * A gateway needs no account to send money to -- it collects the money
         * itself and tells us afterwards. What it does need is credentials, and
         * a method that cannot actually start a payment should not be offered
         * at checkout at all: better absent than failing at the last step.
         */
        if ($this->isGateway()) {
            return $this->isGatewayConfigured();
        }

        return filled($this->payee_account_number) || filled($this->payee_qr_image);
    }

    /** Whether this gateway has enough in the environment to be usable. */
    public function isGatewayConfigured(): bool
    {
        return match ($this->code) {
            'esewa' => filled(config('services.esewa.product_code')) && filled(config('services.esewa.secret')),
            'khalti' => filled(config('services.khalti.secret_key')),
            default => false,
        };
    }

    /**
     * What a buyer pays up front on this method to reserve the animal.
     *
     * A method may pin its own fixed advance; otherwise it is the site-wide
     * percentage. Never more than the order itself.
     */
    public function advanceFor(float $total): float
    {
        $advance = match (true) {
            // Nothing set on the method: fall back to the site-wide share.
            $this->advance_amount === null => $total * ((float) Setting::get('advance_percent', 30) / 100),
            $this->advance_type === 'fixed' => (float) $this->advance_amount,
            default => $total * ((float) $this->advance_amount / 100),
        };

        return min(max(round($advance, 2), 0), $total);
    }

    /** How the advance reads in the admin panel and on the storefront. */
    public function getAdvanceLabelAttribute(): ?string
    {
        if (! $this->requires_advance && $this->advance_amount === null) {
            return null;
        }

        if ($this->advance_amount === null) {
            return Setting::get('advance_percent', 30).'% (site default)';
        }

        return $this->advance_type === 'fixed'
            ? Setting::currencySymbol().number_format((float) $this->advance_amount, 2)
            : rtrim(rtrim(number_format((float) $this->advance_amount, 2), '0'), '.').'%';
    }

    /** The plans a buyer may choose when paying with this method. */
    public function paymentPlans(): array
    {
        if (! $this->isPrepayable()) {
            // An admin has said money is wanted up front; there is simply
            // nowhere online to send it yet, so the order still carries the
            // obligation and staff arrange collection. Silently downgrading it
            // to pay-on-delivery would throw the setting away.
            return $this->requires_advance ? ['advance'] : ['on_delivery'];
        }

        // Nothing else. Deferring the whole amount on a method that can take it
        // online is just cash on delivery wearing another name, and cash on
        // delivery is not a way to place an order — it settles one.
        return ['full', 'advance'];
    }

    /** Buyer-facing payee block, or null when there is nowhere to send money. */
    public function payeeDetails(): ?array
    {
        if (! $this->isPrepayable()) {
            return null;
        }

        /*
         * A gateway has no account for the buyer to send to -- it collects the
         * money itself and tells us afterwards. Showing an account number here
         * would invite exactly the hand-made transfer the integration exists to
         * replace, and that payment would then have to be checked by a person.
         */
        if ($this->isGateway()) {
            return null;
        }

        return array_filter([
            'account_name' => $this->payee_account_name,
            'account_number' => $this->payee_account_number,
            'bank_name' => $this->payee_bank_name,
            'qr' => $this->payee_qr_url,
        ], fn ($value) => filled($value));
    }
}
