<?php

namespace SocialDept\AtpParity\Listeners;

use SocialDept\AtpClient\Events\SessionAuthenticated;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;

/**
 * Automatically retries pending syncs when a user re-authenticates.
 *
 * This listener is only registered when both pending_syncs.enabled
 * and pending_syncs.auto_retry are set to true in config.
 */
class RetryPendingSyncsOnAuth
{
    public function __construct(
        protected PendingSyncManager $manager,
    ) {}

    /**
     * Handle the SessionAuthenticated event.
     */
    public function handle(SessionAuthenticated $event): void
    {
        if (! $this->manager->isEnabled()) {
            return;
        }

        $did = $event->token->did;

        if ($this->manager->hasPendingSyncs($did)) {
            $this->manager->retryForDid($did);
        }
    }
}
