<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\Concerns\AutoSyncsWithReference;

/**
 * A main + reference pair that resyncs both halves on save.
 */
class AutoSyncReferenceModel extends Model
{
    use AutoSyncsWithReference;

    protected $table = 'reference_models';

    protected $guarded = [];
}
