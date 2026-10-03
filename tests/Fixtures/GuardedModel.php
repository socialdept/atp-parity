<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasAtpRecord;

/**
 * A model that guards its attributes the way a real one does, so the ingest path is
 * exercised against mass-assignment rules rather than an open `$guarded = []`.
 */
class GuardedModel extends Model
{
    use HasAtpRecord;

    protected $table = 'test_models';

    protected $fillable = ['content'];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
