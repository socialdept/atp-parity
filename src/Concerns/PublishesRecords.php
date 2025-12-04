<?php

namespace SocialDept\AtpParity\Concerns;

use SocialDept\AtpParity\Publish\PublishResult;
use SocialDept\AtpParity\Publish\PublishService;

/**
 * Trait for Eloquent models that can be manually published to AT Protocol.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait PublishesRecords
{
    use HasAtpRecord;

    /**
     * Publish this model to AT Protocol.
     *
     * If the model has a DID association (via did column or relationship),
     * it will be used. Otherwise, use publishAs() to specify the DID.
     */
    public function publish(): PublishResult
    {
        return app(PublishService::class)->publish($this);
    }

    /**
     * Publish this model as a specific user.
     */
    public function publishAs(string $did): PublishResult
    {
        return app(PublishService::class)->publishAs($did, $this);
    }

    /**
     * Update the published record on AT Protocol.
     */
    public function republish(): PublishResult
    {
        return app(PublishService::class)->update($this);
    }

    /**
     * Delete the record from AT Protocol.
     */
    public function unpublish(): bool
    {
        return app(PublishService::class)->delete($this);
    }

    /**
     * Check if this model has been published to AT Protocol.
     */
    public function isPublished(): bool
    {
        return $this->hasAtpRecord();
    }
}
