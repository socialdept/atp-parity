<?php

namespace SocialDept\AtpParity\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpSchema\Data\Data;

/**
 * Adapter for using atp-schema generated DTOs as record types.
 *
 * This allows you to use the auto-generated schema classes directly
 * without creating custom Record classes.
 *
 * Example:
 *
 * use SocialDept\AtpSchema\Generated\App\Bsky\Feed\Post;
 * use App\Models\Post as PostModel;
 *
 * $mapper = new SchemaMapper(
 *     schemaClass: Post::class,
 *     modelClass: PostModel::class,
 *     toAttributes: fn(Post $p) => [
 *         'content' => $p->text,
 *         'published_at' => $p->createdAt,
 *     ],
 *     toRecordData: fn(PostModel $m) => [
 *         'text' => $m->content,
 *         'createdAt' => $m->published_at->toIso8601String(),
 *     ],
 * );
 *
 * $registry->register($mapper);
 *
 * @template TSchema of Data
 * @template TModel of Model
 *
 * @extends RecordMapper<TSchema, TModel>
 */
class SchemaMapper extends RecordMapper
{
    /**
     * @param  class-string<TSchema>  $schemaClass  The atp-schema generated class
     * @param  class-string<TModel>  $modelClass  The Eloquent model class
     * @param  Closure(TSchema): array  $toAttributes  Convert schema to model attributes
     * @param  Closure(TModel): array  $toRecordData  Convert model to record data
     */
    public function __construct(
        protected string $schemaClass,
        protected string $modelClass,
        protected Closure $toAttributes,
        protected Closure $toRecordData,
    ) {}

    public function recordClass(): string
    {
        return $this->schemaClass;
    }

    public function modelClass(): string
    {
        return $this->modelClass;
    }

    protected function recordToAttributes(Data $record): array
    {
        return ($this->toAttributes)($record);
    }

    protected function modelToRecordData(Model $model): array
    {
        return ($this->toRecordData)($model);
    }
}
