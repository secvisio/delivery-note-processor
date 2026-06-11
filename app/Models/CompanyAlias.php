<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAlias extends Model
{
    protected $fillable = [
        'company_identity_id',
        'raw_name',
        'normalized_name',
        'first_seen_at',
        'last_seen_at',
        'times_seen',
        'match_confidence',
        'source',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'times_seen' => 'int',
        'match_confidence' => 'float',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CompanyIdentity::class, 'company_identity_id');
    }
}
