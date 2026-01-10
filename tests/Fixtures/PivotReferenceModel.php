<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\ReferencesAtpRecord;
use SocialDept\AtpParity\Concerns\SyncsWithReference;

class PivotReferenceModel extends Model
{
    use ReferencesAtpRecord;
    use SyncsWithReference;

    protected $table = 'pivot_reference_models';

    protected $guarded = [];

    protected $casts = [
        'atp_synced_at' => 'datetime',
    ];

    public function getMainModelForeignKey(): string
    {
        return 'main_model_id';
    }

    public function getMainModelClass(): string
    {
        return MainModel::class;
    }
}
