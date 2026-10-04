<?php

namespace SocialDept\AtpParity\Support;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpSupport\AtUri;

/**
 * The repo a model belongs to, resolved before a sync starts.
 *
 * An overflow blob must be uploaded to the repo the record lands in, or the
 * record points at a blob that PDS does not hold.
 */
final class ModelDid
{
    public static function for(Model $model, ?string $uri = null): ?string
    {
        if (isset($model->did)) {
            return $model->did;
        }

        if (method_exists($model, 'user') && $model->user?->did) {
            return $model->user->did;
        }

        if (method_exists($model, 'author') && $model->author?->did) {
            return $model->author->did;
        }

        return $uri ? AtUri::parse($uri)?->did : null;
    }
}
