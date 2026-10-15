<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A customer and/or vendor. Optionally linked to an application model
 * (e.g. a member or supplier record) through "contactable".
 *
 * @property int $id
 * @property string $name
 * @property bool $is_customer
 * @property bool $is_vendor
 * @property ?string $currency
 * @property ?int $payment_terms_days
 */
class Contact extends Model
{
    protected $table = 'bookkeeper_contacts';

    protected $casts = [
        'is_customer' => 'boolean',
        'is_vendor' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('is_customer', true);
    }

    public function scopeVendors(Builder $query): Builder
    {
        return $query->where('is_vendor', true);
    }
}
