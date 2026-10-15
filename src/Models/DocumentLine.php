<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Casts\MoneyCast;

/**
 * @property int $id
 * @property int $document_id
 * @property int $account_id
 * @property ?string $description
 * @property string $quantity
 * @property \OtimOtim\BookKeeper\Support\Money $unit_price
 * @property \OtimOtim\BookKeeper\Support\Money $discount
 * @property ?int $tax_rate_id
 * @property \OtimOtim\BookKeeper\Support\Money $net_amount
 * @property \OtimOtim\BookKeeper\Support\Money $tax_amount
 * @property \OtimOtim\BookKeeper\Support\Money $total
 */
class DocumentLine extends Model
{
    protected $table = 'bookkeeper_document_lines';

    protected $casts = [
        'unit_price' => MoneyCast::class.':document.currency',
        'discount' => MoneyCast::class.':document.currency',
        'net_amount' => MoneyCast::class.':document.currency',
        'tax_amount' => MoneyCast::class.':document.currency',
        'total' => MoneyCast::class.':document.currency',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
