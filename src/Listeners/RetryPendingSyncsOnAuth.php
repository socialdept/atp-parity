<?php

namespace SocialDept\AtpParity\Listeners;

use Illuminate\Support\Facades\Log;
use SocialDept\AtpClient\Events\SessionAuthenticated;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use Throwable;

/**
 * Automatically retries pending syncs when a user re-authenticates.
 *
 * This listener is only registered when both pending_syncs.enabled
 * and pending_syncs.auto_retry are set to true in config.
 *
 * The retry is deferred until after the HTTP response completes to ensure
 * that the new tokens have been saved to the database before attempting
 * to use them for sync operations.
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

        $this->log('info', 'Scheduling pending syncs retry after response', [
            'did' => $did,
            'count' => $count,
        ]);

        // Defer the retry until after the HTTP response completes.
        // This ensures the new tokens have been saved to the database
        // before we attempt to use them for sync operations.
        dispatch(function () use ($did) {
            $this->retryPendingSyncs($did);
        })->afterResponse();
    }

    /**
     * Perform the actual retry of pending syncs.
     */
    protected function retryPendingSyncs(string $did): void
    {
        $manager = app(PendingSyncManager::class);

        $this->log('info', 'Retrying pending syncs after reauth', [
            'did' => $did,
            'count' => $manager->countForDid($did),
        ]);

        try {
            $result = $manager->retryForDid($did);

            $this->log('info', 'Pending syncs retry completed', [
                'did' => $did,
                'total' => $result->total,
                'succeeded' => $result->succeeded,
                'failed' => $result->failed,
                'skipped' => $result->skipped,
            ]);
        } catch (Throwable $e) {
            $this->log('error', 'Pending syncs retry failed with exception', [
                'did' => $did,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
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
