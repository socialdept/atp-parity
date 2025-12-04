<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasAtpRecord;

/**
 * Test model for unit testing.
 */
class TestModel extends Model
{
    use HasAtpRecord;

    protected $table = 'test_models';

    protected $guarded = [];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
