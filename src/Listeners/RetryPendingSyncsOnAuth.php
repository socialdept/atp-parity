<?php

namespace SocialDept\AtpParity\Listeners;

use Illuminate\Support\Facades\Log;
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
            $this->log('debug', 'Pending syncs feature is disabled, skipping retry');

            return;
        }

        $did = $event->token->did;

        if (! $this->manager->hasPendingSyncs($did)) {
            $this->log('debug', 'No pending syncs found for DID', ['did' => $did]);

            return;
        }

        $count = $this->manager->countForDid($did);

        $this->log('info', 'Retrying pending syncs after reauth', [
            'did' => $did,
            'count' => $count,
        ]);

        $result = $this->manager->retryForDid($did);

        $this->log('info', 'Pending syncs retry completed', [
            'did' => $did,
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'failed' => $result->failed,
            'skipped' => $result->skipped,
        ]);
    }

    /**
     * Log a message if logging is enabled.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (config('parity.pending_syncs.log', false)) {
            Log::{$level}("[Parity] {$message}", $context);
        }
    }
}
