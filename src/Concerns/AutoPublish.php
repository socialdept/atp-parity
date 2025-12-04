<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Publish\PublishService;

/**
 * Trait for Eloquent models that automatically publish to AT Protocol.
 *
 * This trait sets up model observers to automatically publish, update,
 * and unpublish records when the model is created, updated, or deleted.
 *
 * Override shouldAutoPublish() and shouldAutoUnpublish() to customize
 * the conditions under which auto-publishing occurs.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait AutoPublish
{
    use PublishesRecords;

    /**
     * Boot the AutoPublish trait.
     */
    public static function bootAutoPublish(): void
    {
        static::created(function ($model) {
            if ($model->shouldAutoPublish()) {
                app(PublishService::class)->publish($model);
            }
        });

        static::updated(function ($model) {
            if ($model->isPublished() && $model->shouldAutoPublish()) {
                app(PublishService::class)->update($model);
            }
        });

        static::deleted(function ($model) {
            if ($model->isPublished() && $model->shouldAutoUnpublish()) {
                app(PublishService::class)->delete($model);
            }
        });
    }

    /**
     * Determine if the model should be auto-published.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoPublish(): bool
    {
        return true;
    }

    /**
     * Determine if the model should be auto-unpublished when deleted.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoUnpublish(): bool
    {
        return true;
    }

    /**
     * Get the DID to use for auto-publishing.
     *
     * Override this method to customize DID resolution.
     */
    public function getAutoPublishDid(): ?string
    {
        // Check for did column
        if (isset($this->did)) {
            return $this->did;
        }

        // Check for user relationship with did
        if (method_exists($this, 'user') && $this->user?->did) {
            return $this->user->did;
        }

        // Check for author relationship with did
        if (method_exists($this, 'author') && $this->author?->did) {
            return $this->author->did;
        }

        return null;
    }
}
