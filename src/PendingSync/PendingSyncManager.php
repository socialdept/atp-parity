<?php

namespace SocialDept\AtpParity\PendingSync;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Contracts\PendingSyncStore;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\Events\PendingSyncCaptured;
use SocialDept\AtpParity\Events\PendingSyncFailed;
use SocialDept\AtpParity\Events\PendingSyncRetried;
use SocialDept\AtpParity\MapperRegistry;
use SocialDept\AtpParity\Sync\ReferenceSyncService;
use SocialDept\AtpParity\Sync\SyncService;
use Throwable;

/**
 * Manages pending sync operations for retry after reauth.
 */
class PendingSyncManager
{
    public function __construct(
        protected PendingSyncStore $store,
        protected SyncService $syncService,
        protected ReferenceSyncService $referenceSyncService,
        protected MapperRegistry $registry,
    ) {}

    /**
     * Check if pending syncs feature is enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) config('parity.pending_syncs.enabled', false);
    }

    /**
     * Capture a failed sync operation for later retry.
     */
    public function capture(
        string $did,
        Model $model,
        PendingSyncOperation $operation,
        ?ReferenceMapper $referenceMapper = null,
    ): PendingSync {
        // Remove any existing pending sync for this model to avoid duplicates
        $this->store->removeForModel(get_class($model), $model->getKey());

        $pendingSync = new PendingSync(
            id: Str::ulid()->toString(),
            did: $did,
            modelClass: get_class($model),
            modelId: $model->getKey(),
            operation: $operation,
            referenceMapperClass: $referenceMapper ? get_class($referenceMapper) : null,
            createdAt: CarbonImmutable::now(),
            attempts: 0,
        );

        $this->store->store($pendingSync);

        $this->log('info', 'Pending sync captured', [
            'id' => $pendingSync->id,
            'did' => $did,
            'model' => $pendingSync->modelClass,
            'model_id' => $pendingSync->modelId,
            'operation' => $operation->value,
        ]);

        event(new PendingSyncCaptured($pendingSync, $model));

        return $pendingSync;
    }

    /**
     * Retry all pending syncs for a DID.
     */
    public function retryForDid(string $did): PendingSyncRetryResult
    {
        $pending = $this->store->forDid($did);
        $maxAttempts = config('parity.pending_syncs.max_attempts', 3);

        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($pending as $pendingSync) {
            // Skip if max attempts exceeded
            if ($pendingSync->hasExceededMaxAttempts($maxAttempts)) {
                $this->store->remove($pendingSync->id);
                $skipped++;

                $this->log('warning', 'Pending sync skipped (max attempts exceeded)', [
                    'id' => $pendingSync->id,
                    'model' => $pendingSync->modelClass,
                    'model_id' => $pendingSync->modelId,
                    'attempts' => $pendingSync->attempts,
                ]);

                continue;
            }

            // Increment attempts before trying
            $pendingSync = $pendingSync->withIncrementedAttempts();
            $this->store->update($pendingSync);

            try {
                $result = $this->retry($pendingSync);

                if ($result) {
                    $this->store->remove($pendingSync->id);
                    $succeeded++;

                    $this->log('info', 'Pending sync retry succeeded', [
                        'id' => $pendingSync->id,
                        'model' => $pendingSync->modelClass,
                        'model_id' => $pendingSync->modelId,
                    ]);

                    event(new PendingSyncRetried($pendingSync, true));
                } else {
                    $failed++;
                    $errors[] = "Failed to retry sync for {$pendingSync->modelClass}:{$pendingSync->modelId}";

                    $this->log('warning', 'Pending sync retry failed', [
                        'id' => $pendingSync->id,
                        'model' => $pendingSync->modelClass,
                        'model_id' => $pendingSync->modelId,
                        'attempts' => $pendingSync->attempts,
                    ]);

                    event(new PendingSyncRetried($pendingSync, false));
                }
            } catch (Throwable $e) {
                // Auth exceptions should bubble up - user still needs reauth
                if ($this->isAuthException($e)) {
                    throw $e;
                }

                $failed++;
                $errors[] = $e->getMessage();

                $this->log('error', 'Pending sync retry exception', [
                    'id' => $pendingSync->id,
                    'model' => $pendingSync->modelClass,
                    'model_id' => $pendingSync->modelId,
                    'error' => $e->getMessage(),
                ]);

                event(new PendingSyncFailed($pendingSync, $e));
            }
        }

        return new PendingSyncRetryResult(
            total: count($pending),
            succeeded: $succeeded,
            failed: $failed,
            skipped: $skipped,
            errors: $errors,
        );
    }

    /**
     * Retry a single pending sync.
     */
    protected function retry(PendingSync $pendingSync): bool
    {
        // Find the model
        $modelClass = $pendingSync->modelClass;
        $model = $modelClass::find($pendingSync->modelId);

        // Model was deleted - consider it "handled"
        if (! $model) {
            return true;
        }

        return match ($pendingSync->operation) {
            PendingSyncOperation::Sync => $this->retrySyncAs($pendingSync->did, $model),
            PendingSyncOperation::Resync => $this->retryResync($model),
            PendingSyncOperation::Unsync => $this->retryUnsync($model),
            PendingSyncOperation::SyncWithReference => $this->retrySyncWithReference($pendingSync, $model),
            PendingSyncOperation::ResyncWithReference => $this->retryResyncWithReference($pendingSync, $model),
            PendingSyncOperation::UnsyncWithReference => $this->retryUnsyncWithReference($pendingSync, $model),
        };
    }

    protected function retrySyncAs(string $did, Model $model): bool
    {
        return $this->syncService->syncAs($did, $model)->isSuccess();
    }

    protected function retryResync(Model $model): bool
    {
        return $this->syncService->resync($model)->isSuccess();
    }

    protected function retryUnsync(Model $model): bool
    {
        return $this->syncService->unsync($model);
    }

    protected function retrySyncWithReference(PendingSync $pendingSync, Model $model): bool
    {
        $mapper = $this->resolveReferenceMapper($pendingSync);

        if (! $mapper) {
            return false;
        }

        return $this->referenceSyncService
            ->syncWithReference($pendingSync->did, $model, $mapper)
            ->isSuccess();
    }

    protected function retryResyncWithReference(PendingSync $pendingSync, Model $model): bool
    {
        $mapper = $this->resolveReferenceMapper($pendingSync);

        if (! $mapper) {
            return false;
        }

        return $this->referenceSyncService
            ->resyncWithReference($model, $mapper)
            ->isSuccess();
    }

    protected function retryUnsyncWithReference(PendingSync $pendingSync, Model $model): bool
    {
        $mapper = $this->resolveReferenceMapper($pendingSync);

        if (! $mapper) {
            return false;
        }

        return $this->referenceSyncService->unsyncWithReference($model, $mapper);
    }

    /**
     * Resolve reference mapper from class name.
     */
    protected function resolveReferenceMapper(PendingSync $pendingSync): ?ReferenceMapper
    {
        if (! $pendingSync->referenceMapperClass) {
            return null;
        }

        return app($pendingSync->referenceMapperClass);
    }

    /**
     * Check if exception is an auth exception that should bubble up.
     */
    protected function isAuthException(Throwable $e): bool
    {
        return $e instanceof OAuthSessionInvalidException
            || $e instanceof AuthenticationException;
    }

    /**
     * Get pending syncs count for a DID.
     */
    public function countForDid(string $did): int
    {
        return $this->store->countForDid($did);
    }

    /**
     * Check if a DID has pending syncs.
     */
    public function hasPendingSyncs(string $did): bool
    {
        return $this->store->hasForDid($did);
    }

    /**
     * Get all pending syncs for a DID.
     *
     * @return array<PendingSync>
     */
    public function forDid(string $did): array
    {
        return $this->store->forDid($did);
    }

    /**
     * Clean up expired pending syncs.
     */
    public function cleanup(): int
    {
        return $this->store->removeExpired();
    }

    /**
     * Remove a specific pending sync.
     */
    public function remove(string $id): void
    {
        $this->store->remove($id);
    }

    /**
     * Remove all pending syncs for a DID.
     */
    public function removeForDid(string $did): int
    {
        return $this->store->removeForDid($did);
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
