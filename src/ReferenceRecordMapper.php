<?php

namespace SocialDept\AtpParity;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
use SocialDept\AtpParity\Contracts\ReferenceMapper;
use SocialDept\AtpParity\Data\DeferredReference;
use SocialDept\AtpParity\Enums\ReferenceFormat;
use SocialDept\AtpParity\Events\DeferredReferenceParked;
use SocialDept\AtpSchema\Data\Data;
use SocialDept\AtpSchema\Generated\Com\Atproto\Repo\StrongRef;

/**
 * Abstract base class for reference record mappers.
 *
 * Reference records point to "main" records using strong references or AT-URIs.
 * Example: app.offprint.publication pointing to site.standard.publication
 *
 * @template TRecord of Data
 * @template TModel of Model
 *
 * @extends RecordMapper<TRecord, TModel>
 * @implements ReferenceMapper<TRecord, TModel>
 */
abstract class ReferenceRecordMapper extends RecordMapper implements ReferenceMapper
{
    /**
     * The property in the record that contains the reference.
     */
    protected string $referenceProperty = 'subject';

    /**
     * The reference format (AtUri or StrongRef).
     */
    protected ReferenceFormat $referenceFormat = ReferenceFormat::StrongRef;

    /**
     * The column storing the reference record's URI.
     */
    protected string $referenceUriColumn = 'atp_reference_uri';

    /**
     * The column storing the reference record's CID.
     */
    protected string $referenceCidColumn = 'atp_reference_cid';

    /**
     * Get the main record's lexicon NSID.
     */
    abstract public function mainLexicon(): string;

    public function referenceProperty(): string
    {
        return $this->referenceProperty;
    }

    public function referenceFormat(): ReferenceFormat
    {
        return $this->referenceFormat;
    }

    public function referenceUriColumn(): string
    {
        return config('atp-parity.references.columns.reference_uri', $this->referenceUriColumn);
    }

    public function referenceCidColumn(): string
    {
        return config('atp-parity.references.columns.reference_cid', $this->referenceCidColumn);
    }

    public function mainMapper(): ?RecordMapperContract
    {
        $registry = app(MapperRegistry::class);

        return $registry->forLexicon($this->mainLexicon());
    }

    public function extractReference(Data $record): ?StrongRef
    {
        $property = $this->referenceProperty();
        $data = $record->toArray();

        if (! isset($data[$property])) {
            return null;
        }

        $ref = $data[$property];

        // Handle string format (at-uri)
        if (is_string($ref)) {
            return new StrongRef(uri: $ref, cid: '');
        }

        // Handle object/array format (strongref)
        if (is_array($ref) && isset($ref['uri'])) {
            return new StrongRef(
                uri: $ref['uri'],
                cid: $ref['cid'] ?? ''
            );
        }

        return null;
    }

    /**
     * Build the reference data from a model.
     *
     * Uses the main record's atp_uri (and atp_cid for strongref format)
     * to build the reference that points to it.
     *
     * @return string|array{uri: string, cid: string}
     */
    public function buildReference(Model $model): string|array
    {
        if ($this->referenceFormat === ReferenceFormat::AtUri) {
            return $this->buildAtUriRef($model);
        }

        return $this->buildStrongRef($model)->toArray();
    }

    /**
     * Build a StrongRef from the model's main record metadata.
     *
     * @throws \InvalidArgumentException If main record metadata is missing
     */
    public function buildStrongRef(Model $model): StrongRef
    {
        $mainUri = $model->{$this->uriColumn()};
        $mainCid = $model->{$this->cidColumn()};

        if (! $mainUri || ! $mainCid) {
            throw new \InvalidArgumentException(
                "Model must have both {$this->uriColumn()} and {$this->cidColumn()} set to build a strong reference"
            );
        }

        return new StrongRef(uri: $mainUri, cid: $mainCid);
    }

    /**
     * Build an AT-URI string from the model's main record metadata.
     *
     * @throws \InvalidArgumentException If main record URI is missing
     */
    public function buildAtUriRef(Model $model): string
    {
        $mainUri = $model->{$this->uriColumn()};

        if (! $mainUri) {
            throw new \InvalidArgumentException(
                "Model must have {$this->uriColumn()} set to build an AT-URI reference"
            );
        }

        return $mainUri;
    }

    /**
     * Apply reference metadata from the record to model attributes.
     */
    protected function applyReferenceToAttributes(array $attributes, Data $record): array
    {
        // Reference records store their URI in the reference columns, not main columns
        // The main columns (atp_uri, atp_cid) store what they point TO
        $ref = $this->extractReference($record);

        if ($ref) {
            $attributes[$this->uriColumn()] = $ref->uri;

            if ($ref->cid) {
                $attributes[$this->cidColumn()] = $ref->cid;
            }
        }

        return $attributes;
    }

    /**
     * Apply an inbound reference record to the model it points at.
     *
     * A reference record never creates anything. It carries no content of its
     * own — only a strong-ref at a main record — so its job is to annotate that
     * model with its URI and CID, which is how the model declares itself part of
     * this application.
     *
     * The base implementation cannot do this: it resolves by the inbound
     * record's own URI (which `atp_uri` never holds — that column stores what
     * the reference points *at*), so the lookup always misses, and `applyMeta()`
     * then writes the reference's URI over `atp_uri` on a blank row. This
     * override is why `ReferenceRecordMapper` exists on the inbound path at all.
     *
     * Returns null when the target has not arrived yet. Ordering is not
     * guaranteed — a producer may write the reference first — so callers should
     * treat null-with-a-target as "defer", not "reject"; see
     * {@see referenceTargetMissing()}.
     *
     * @param  array<string, mixed>  $meta
     */
    public function upsert(Data $record, array $meta = []): ?Model
    {
        $ref = $this->extractReference($record);

        if (! $ref) {
            return null;
        }

        // By the main URI first; fall back to the reference column for a model
        // we have already linked, so re-delivery is idempotent.
        $existing = $this->findByUri($ref->uri)
            ?? (isset($meta['uri']) ? $this->findByReferenceUri($meta['uri']) : null);

        if (! $this->shouldImport($record, $meta + ['existing' => $existing])) {
            return null;
        }

        if (! $existing) {
            $this->referenceTargetMissing($record, $meta, $ref->uri);

            return null;
        }

        if (isset($meta['uri'])) {
            $existing->setAttribute($this->referenceUriColumn(), $meta['uri']);
        }

        if (isset($meta['cid'])) {
            $existing->setAttribute($this->referenceCidColumn(), $meta['cid']);
        }

        $existing->save();

        return $existing;
    }

    /**
     * Hook for a reference whose target is not here yet.
     *
     * Refusing would be data loss: the delivering consumer has already acked, so
     * the reference is simply dropped and only a manual cursor rewind recovers
     * it. Override to park it and replay when the target lands.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function referenceTargetMissing(Data $record, array $meta, string $targetUri): void
    {
        if (! config('atp-parity.deferred_references.enabled', true)) {
            return;
        }

        try {
            app(DeferredReferenceStore::class)->park(new DeferredReference(
                referenceUri: $meta['uri'] ?? '',
                targetUri: $targetUri,
                collection: $this->lexicon(),
                did: $meta['did'] ?? '',
                cid: $meta['cid'] ?? null,
                record: $record->toArray(),
                parkedAt: new DateTimeImmutable(),
            ));
        } catch (\Throwable $e) {
            Log::warning('[Parity] Could not park deferred reference', [
                'reference_uri' => $meta['uri'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        event(new DeferredReferenceParked($meta['uri'] ?? '', $targetUri, $this->lexicon()));
    }

    /**
     * Find model by reference record URI.
     */
    public function findByReferenceUri(string $uri): ?Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::where($this->referenceUriColumn(), $uri)->first();
    }
}
