<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyIdentity extends Model
{
    protected $fillable = [
        'canonical_name',
        'rename_slug',
        'normalized_canonical',
        'created_from_raw_name',
        'first_seen_at',
        'last_seen_at',
        'confidence_at_creation',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'confidence_at_creation' => 'float',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(CompanyAlias::class);
    }
}
