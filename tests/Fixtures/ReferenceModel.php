<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\HasReferenceRecord;
use SocialDept\AtpParity\Concerns\SyncsWithReference;

class ReferenceModel extends Model
{
    use HasReferenceRecord;
    use SyncsWithReference;

    protected $table = 'reference_models';

    protected $guarded = [];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];
}
