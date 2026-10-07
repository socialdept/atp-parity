<?php

namespace SocialDept\AtpParity\Concerns;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpClient\Exceptions\AuthenticationException;
use SocialDept\AtpClient\Exceptions\OAuthSessionInvalidException;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\PendingSync\PendingSyncManager;
use SocialDept\AtpParity\Support\AutoSync;
use SocialDept\AtpParity\Sync\ReferenceSyncService;

/**
 * Trait for Eloquent models that automatically sync both main and reference records.
 *
 * This trait sets up model observers to automatically create, update,
 * and delete both main and reference records when the model changes.
 *
 * Use this instead of AutoSyncsWithAtp when your model needs both
 * a main record (third-party lexicon) and a reference record (your lexicon).
 *
 * Nothing syncs inside {@see AutoSync::without()}, which is where every inbound
 * record is applied.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait AutoSyncsWithReference
{
    use HasReferenceRecord;

    /**
     * Boot the AutoSyncsWithReference trait.
     */
    public static function bootAutoSyncsWithReference(): void
    {
        static::created(function ($model) {
            if (AutoSync::isSuppressed()) {
                return;
            }

            if ($model->shouldAutoSyncReference()) {
                $did = $model->syncAsDid();
                $mapper = $model->getReferenceMapper();

                if ($did && $mapper) {
                    try {
                        $result = app(ReferenceSyncService::class)->syncWithReference($did, $model, $mapper);

                        if ($result->hasReferenceFailure()) {
                            static::capturePendingSyncWithReference(
                                $model,
                                $did,
                                PendingSyncOperation::SyncWithReference,
                                $mapper
                            );
                        }
                    } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                        static::capturePendingSyncWithReference(
                            $model,
                            $did,
                            PendingSyncOperation::SyncWithReference,
                            $mapper
                        );

                        throw $e;
                    }
                }
            }
        });

        static::updated(function ($model) {
            if (AutoSync::isSuppressed()) {
                return;
            }

            if ($model->isFullySynced() && $model->shouldAutoSyncReference()) {
                $mapper = $model->getReferenceMapper();

                if ($mapper && static::pairCouldHaveChanged($model, $mapper)) {
                    try {
                        // Resync BOTH main and reference records
                        $result = app(ReferenceSyncService::class)->resyncWithReference($model, $mapper);

                        if ($result->hasReferenceFailure()) {
                            $did = $model->getAtpDid() ?? $model->syncAsDid();

                            if ($did) {
                                static::capturePendingSyncWithReference(
                                    $model,
                                    $did,
                                    PendingSyncOperation::ResyncWithReference,
                                    $mapper
                                );
                            }
                        }
                    } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                        $did = $model->getAtpDid() ?? $model->syncAsDid();

                        if ($did) {
                            static::capturePendingSyncWithReference(
                                $model,
                                $did,
                                PendingSyncOperation::ResyncWithReference,
                                $mapper
                            );
                        }

                        throw $e;
                    }
                }
            }
        });

        static::deleted(function ($model) {
            if (AutoSync::isSuppressed()) {
                return;
            }

            if ($model->shouldAutoUnsyncReference()) {
                $mapper = $model->getReferenceMapper();

                if ($mapper) {
                    try {
                        app(ReferenceSyncService::class)->unsyncWithReference($model, $mapper);
                    } catch (OAuthSessionInvalidException|AuthenticationException $e) {
                        $did = $model->getAtpDid() ?? $model->syncAsDid();

                        if ($did) {
                            static::capturePendingSyncWithReference(
                                $model,
                                $did,
                                PendingSyncOperation::UnsyncWithReference,
                                $mapper
                            );
                        }

                        throw $e;
                    }
                }
            }
        });
    }

    /**
     * Whether this update could have changed either half of the pair.
     *
     * Considers both mappers because this hook resyncs them together.
     *
     * @see \SocialDept\AtpParity\Concerns\AutoSyncsWithAtp::recordCouldHaveChanged()
     */
    protected static function pairCouldHaveChanged(Model $model, ReferenceMapper $referenceMapper): bool
    {
        $columns = [];

        foreach ([$referenceMapper, $referenceMapper->mainMapper()] as $mapper) {
            if (! $mapper) {
                continue;
            }

            $mapperColumns = $mapper->recordColumns();

            if ($mapperColumns === null || $mapperColumns === []) {
                return true;
            }

            $columns = [...$columns, ...$mapperColumns];
        }

        return $columns === [] || $model->wasChanged(array_values(array_unique($columns)));
    }

    /**
     * Capture a pending sync for retry after reauth.
     */
    protected static function capturePendingSyncWithReference(
        Model $model,
        string $did,
        PendingSyncOperation $operation,
        ReferenceMapper $mapper
    ): void {
        $manager = app(PendingSyncManager::class);

        if ($manager->isEnabled()) {
            $manager->capture($did, $model, $operation, $mapper);
        }
    }

    /**
     * Determine if the model should auto-sync main + reference.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoSyncReference(): bool
    {
        return true;
    }

    /**
     * Determine if the model should auto-unsync when deleted.
     *
     * Override this method to add custom conditions.
     */
    public function shouldAutoUnsyncReference(): bool
    {
        return true;
    }

    /**
     * Get the DID to use for syncing.
     *
     * Override this method to customize DID resolution.
     */
    public function syncAsDid(): ?string
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
