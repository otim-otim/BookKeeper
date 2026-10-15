<?php

namespace OtimOtim\BookKeeper\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OtimOtim\BookKeeper\Casts\DateCast;
use OtimOtim\BookKeeper\Casts\MoneyCast;

class AssetDepreciation extends Model
{
    protected $table = 'bookkeeper_asset_depreciations';

    protected $casts = [
        'period_end' => DateCast::class,
        'amount' => MoneyCast::class,
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
