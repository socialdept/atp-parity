<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\AutoSyncsWithAtp;
use SocialDept\AtpParity\Concerns\HasAtpRecord;

/**
 * A model that resyncs on save, so the gate deciding whether a save is worth a
 * write has something to act on.
 */
class AutoSyncModel extends Model
{
    use AutoSyncsWithAtp;
    use HasAtpRecord;

    protected $table = 'test_models';

    protected $guarded = [];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
