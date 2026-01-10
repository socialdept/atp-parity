<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasAtpRecord;

class MainModel extends Model
{
    use HasAtpRecord;

    protected $table = 'main_models';

    protected $guarded = [];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
