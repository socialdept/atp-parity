<?php

namespace SocialDept\AtpParity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Attributes\Lexicon;
use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Contracts\RecordMapper as RecordMapperContract;
use SocialDept\AtpParity\Enums\ValidationMode;
use SocialDept\AtpParity\Events\DeferredReferenceResolved;
use SocialDept\AtpParity\Fields\FieldMap;
use SocialDept\AtpParity\Support\AutoSync;
use SocialDept\AtpParity\Support\BlobCid;
use SocialDept\AtpParity\Support\BlobReferences;
use SocialDept\AtpParity\Support\ModelDid;
use SocialDept\AtpParity\Support\RecordSize;
use SocialDept\AtpParity\Upcasting\UpcasterChain;
use SocialDept\AtpSchema\Data\BlobReference;
use SocialDept\AtpSchema\Data\Data;
use Throwable;

/**
 * Abstract base class for bidirectional Record <-> Model mapping.
 *
 * @template TRecord of Data
 * @template TModel of Model
 *
 * @implements RecordMapperContract<TRecord, TModel>
 */
abstract class RecordMapper implements RecordMapperContract
{
    /**
     * @var array<class-string, string|null>
     */
    private static array $lexicons = [];

    private ?FieldMap $fieldMap = null;

    protected static bool $overflowEnabled = true;

    protected static bool $overflowUploads = true;

    /**
     * Get the Record class this mapper handles.
     *
     * @return class-string<TRecord>
     */
    abstract public function recordClass(): string;

    /**
     * Get the Model class this mapper handles.
     *
     * @return class-string<TModel>
     */
    abstract public function modelClass(): string;

    /**
     * Map record properties to model attributes.
     *
     * @param  TRecord  $record
     * @return array<string, mixed>
     */
    protected function recordToAttributes(Data $record): array
    {
        $this->assertDeclared(__FUNCTION__);

        return $this->fieldMap()->toAttributes($record);
    }

    /**
     * Map model attributes to record properties.
     *
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    protected function modelToRecordData(Model $model): array
    {
        $this->assertDeclared(__FUNCTION__);

        return $this->fieldMap()->toRecordData($model);
    }


    private function assertDeclared(string $method): void
    {
        if ($this->fieldMap()->isEmpty()) {
            throw new \LogicException(sprintf(
                '%s declares no fields() and does not override %s(). One or the other is required.',
                static::class,
                $method,
            ));
        }
    }

    /**
     * Get the lexicon NSID this mapper handles.
     */
    public function lexicon(): string
    {
        // INFO: memoised. Reading an attribute means a ReflectionClass, and this
        // runs for every inbound record.
        if (! array_key_exists(static::class, self::$lexicons)) {
            $attributes = (new \ReflectionClass(static::class))->getAttributes(Lexicon::class);

            self::$lexicons[static::class] = $attributes === []
                ? null
                : $attributes[0]->newInstance()->nsid;
        }

        if (self::$lexicons[static::class] !== null) {
            return self::$lexicons[static::class];
        }

        $recordClass = $this->recordClass();

        return $recordClass::getLexicon();
    }

    /**
     * The record's fields, keyed by record path. A mapper may override either
     * direction instead.
     *
     * @return array<string, \SocialDept\AtpParity\Fields\Field|string>
     */
    public function fields(): array
    {
        return [];
    }

    public function fieldMap(): FieldMap
    {
        return $this->fieldMap ??= new FieldMap($this->fields());
    }

    /**
     * The model columns that end up in the record, or null when the mapper writes its
     * own directions.
     *
     * INFO: null rather than empty. Empty would read as "nothing in the record" and
     * suppress every write.
     *
     * @return array<int, string>|null
     */
    public function recordColumns(): ?array
    {
        return $this->fieldMap()->isEmpty() ? null : $this->fieldMap()->columns();
    }

    /**
     * Get the column name for storing the AT Protocol URI.
     */
    protected function uriColumn(): string
    {
        return config('atp-parity.columns.uri', 'atp_uri');
    }

    /**
     * Get the column name for storing the AT Protocol CID.
     */
    protected function cidColumn(): string
    {
        return config('atp-parity.columns.cid', 'atp_cid');
    }

    /**
     * Get the column name for storing the sync timestamp.
     */
    protected function syncedAtColumn(): string
    {
        return config('atp-parity.columns.synced_at', 'atp_synced_at');
    }

    /**
     * Get the column name for storing the record's rkey, or null to skip it.
     *
     * Opt-in: only apps that read the rkey back — for routing, reconciliation,
     * or building canonical paths — need it stored, and an app that assigns the
     * rkey itself on create must have imports overwrite it with the real one.
     */
    protected function rkeyColumn(): ?string
    {
        return config('atp-parity.columns.rkey');
    }

    public function toModel(Data $record, array $meta = []): Model
    {
        $modelClass = $this->modelClass();
        $model = new $modelClass($this->applyMeta($this->recordToAttributes($record), $meta));

        $this->applyMetaColumns($model, $meta);

        return $model;
    }

    public function toRecord(Model $model): Data
    {
        $recordClass = $this->recordClass();

        // INFO: blobs resolve first and are the only phase allowed I/O, so the
        // construction below stays free of side effects.
        $data = array_replace_recursive(
            $this->modelToRecordData($model),
            $this->resolveBlobs($model),
        );

        $data = $this->applyOverflow($model, $data);

        // The write half of a deprecation. A mapper only knows the current shape, so
        // keeping a superseded property populated has to happen here.
        $data = app(UpcasterChain::class)->applyDeprecations($this->lexicon(), $data);

        return $recordClass::fromArray($data);
    }

    /**
     * Blob references for every blob field this mapper declares.
     *
     * Without `atp-parity.blobs.resolver` configured, declared blob fields are absent
     * rather than failing, since a mapper may adopt a declaration before the app
     * wires resolution up.
     *
     * @return array<string, mixed>
     */
    protected function resolveBlobs(Model $model): array
    {
        $paths = $this->fieldMap()->blobPaths();

        if ($paths === []) {
            return [];
        }

        $resolver = config('atp-parity.blobs.resolver');

        if ($resolver === null) {
            return [];
        }

        $resolver = is_string($resolver) ? app($resolver) : $resolver;
        $resolved = [];

        foreach ($paths as $path) {
            $reference = $resolver->resolve($model, $path);

            if ($reference !== null) {
                $resolved[$path] = $reference;
            }
        }

        return $resolved;
    }

    /**
     * Build records without overflowing, for callers measuring the inline size.
     *
     * Overflowing during measurement would upload a blob per check and report a
     * size that can never exceed the threshold.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutOverflow(callable $callback): mixed
    {
        $previous = static::$overflowEnabled;
        static::$overflowEnabled = false;

        try {
            return $callback();
        } finally {
            static::$overflowEnabled = $previous;
        }
    }

    /**
     * Build records whose overflow blobs are addressed locally instead of uploaded.
     *
     * A blob's CID is the hash of its bytes, so the record a write would produce
     * can be built and hashed without a PDS. The sync services use this to decide
     * whether a write is needed before uploading anything for it.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutOverflowUploads(callable $callback): mixed
    {
        $previous = static::$overflowUploads;
        static::$overflowUploads = false;

        try {
            return $callback();
        } finally {
            static::$overflowUploads = $previous;
        }
    }

    /**
     * Move declared fields into a blob once the record exceeds their threshold.
     *
     * Measured with the `$type` a PDS adds before it hashes, so the number is
     * the one the server sees.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyOverflow(Model $model, array $data): array
    {
        $fields = $this->fieldMap()->overflowFields();

        if ($fields === [] || ! static::$overflowEnabled) {
            return $data;
        }

        $default = (int) config('atp-parity.records.overflow_bytes', 20480);
        $did = null;

        foreach ($fields as $path => $field) {
            if (! RecordSize::exceeds(['$type' => $this->lexicon()] + $data, $field->overflowThreshold ?? $default)) {
                continue;
            }

            $value = Arr::get($data, $path);

            if ($value === null) {
                continue;
            }

            // INFO: resolved lazily and once. A model with no repo stays inline
            // rather than failing, which is the shape it already had.
            $did ??= ModelDid::for($model, $model->getAttribute($this->uriColumn()));

            if ($did === null) {
                continue;
            }

            $content = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $blob = static::$overflowUploads
                ? app(BlobManager::class)->uploadFromContent($did, $content, $field->overflowMimeType)
                : new BlobReference(ref: BlobCid::for($content), mimeType: $field->overflowMimeType, size: strlen($content));

            Arr::forget($data, $path);
            Arr::set($data, $field->overflowBlobPath, $blob->toArray());

            if ($field->overflowReferencesPath !== null) {
                $references = BlobReferences::collect($value);

                if ($references !== []) {
                    Arr::set($data, $field->overflowReferencesPath, $references);
                }
            }
        }

        return $data;
    }

    /**
     * Put an overflowed field back inline before anything reads the record.
     *
     * The inverse of {@see self::applyOverflow()}. A record whose content sits in
     * a blob carries no inline value, so a gate or a mapper inspecting it sees an
     * empty document and refuses perfectly good content.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function resolveOverflow(Data $record, array $meta): Data
    {
        $fields = $this->fieldMap()->overflowFields();
        $did = $meta['did'] ?? null;

        if ($fields === [] || ! is_string($did)) {
            return $record;
        }

        $data = $record->toArray();
        $restored = false;

        foreach ($fields as $path => $field) {
            $blob = Arr::get($data, (string) $field->overflowBlobPath);

            // An inline value already present wins: it is the one the author wrote.
            if (! is_array($blob) || Arr::get($data, $path) !== null) {
                continue;
            }

            try {
                $decoded = json_decode(
                    app(BlobManager::class)->downloadContent(BlobReference::fromArray($blob), $did),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (Throwable $e) {
                // Leave it overflowed rather than half-read. A caller deciding
                // whether to import needs "could not read" to differ from "empty".
                Log::warning('Could not resolve an overflowed field from its blob', [
                    'lexicon' => $this->lexicon(),
                    'path' => $path,
                    'uri' => $meta['uri'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            Arr::set($data, $path, $decoded);
            Arr::forget($data, (string) $field->overflowBlobPath);

            if ($field->overflowReferencesPath !== null) {
                Arr::forget($data, $field->overflowReferencesPath);
            }

            $restored = true;
        }

        return $restored ? $this->recordClass()::fromArray($data) : $record;
    }

    public function updateModel(Model $model, Data $record, array $meta = []): Model
    {
        $model->fill($this->applyMeta($this->recordToAttributes($record), $meta));

        $this->applyMetaColumns($model, $meta);

        return $model;
    }

    public function findByUri(string $uri): ?Model
    {
        $modelClass = $this->modelClass();

        return $modelClass::where($this->uriColumn(), $uri)->first();
    }

    /**
     * Determine if a record should be imported.
     *
     * Override this method to add custom import conditions.
     * Return false to skip importing this record.
     */
    /**
     * What this mapper accepts from the network, or null when it has not said, in
     * which case nothing is accepted.
     */
    public function accepts(): ?Acceptance
    {
        return null;
    }

    /**
     * Refuses by default: an ingest boundary that forgot its guard should import
     * nothing rather than everything.
     */
    public function shouldImport(Data $record, array $meta = []): bool
    {
        return $this->accepts()?->permits($record, $meta, $this) ?? false;
    }

    /**
     * Get the validation mode for incoming records.
     *
     * Override this method to set a per-mapper validation mode.
     * Return null to use the global config value.
     */
    public function validationMode(): ?ValidationMode
    {
        return null; // Use global config
    }

    public function upsert(Data $record, array $meta = []): ?Model
    {
        // INFO: applying the record, afterUpsert() included, is one inbound write. A
        // save in here that auto-synced would write the record back to the repo it
        // came from, and before afterUpsert() runs it would write stale content.
        return AutoSync::without(function () use ($record, $meta): ?Model {
            $record = $this->resolveOverflow($record, $meta);

            $uri = $meta['uri'] ?? null;
            $existing = $uri ? $this->findByUri($uri) : null;

            // Resolved before the gate and handed over in `$meta['existing']`, so a
            // mapper can tell a create from an update without querying again — the
            // same row was otherwise fetched three times per event. Passed through
            // meta rather than a fourth parameter: changing the signature would
            // break every mapper that overrides this, in every consuming app.
            if (! $this->shouldImport($record, $meta + ['existing' => $existing])) {
                return null;
            }

            if ($uri) {
                if ($existing) {
                    $this->updateModel($existing, $record, $meta);
                    $existing->save();
                    $this->afterUpsert($existing, $record, $meta, created: false);

                    return $existing;
                }
            }

            $model = $this->toModel($record, $meta);
            $model->save();

            // A create is the only moment a parked reference becomes actionable: if
            // the target had existed, the reference would have applied directly.
            // Updates skip this entirely.
            $this->replayDeferredReferences($model, $meta);

            $this->afterUpsert($model, $record, $meta, created: true);

            return $model;
        });
    }

    /**
     * Hook for state that cannot be expressed as fillable attributes.
     *
     * `recordToAttributes()` can only describe columns on the row itself, so a
     * record whose content belongs in related tables — revisions, snapshots,
     * translations, attachments — has nowhere to put it and is silently dropped
     * by `fill()`. This runs once the model is persisted and has a key.
     *
     * `$created` distinguishes the two cases that usually need different
     * handling: seeding initial state versus recording a subsequent change.
     *
     * Runs inside {@see AutoSync::without()}: whatever it saves mirrors the repo, so
     * nothing it does is written back out.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function afterUpsert(Model $model, Data $record, array $meta, bool $created): void
    {
        // No-op by default.
    }

    /**
     * Apply any reference records that arrived before this model existed.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function replayDeferredReferences(Model $model, array $meta): void
    {
        $uri = $meta['uri'] ?? null;

        if (! $uri || ! config('atp-parity.deferred_references.enabled', true)) {
            return;
        }

        $store = app(DeferredReferenceStore::class);
        $registry = app(MapperRegistry::class);

        try {
            $awaiting = $store->awaiting($uri);
        } catch (\Throwable $e) {
            // Never let replay break the create it follows. The commonest cause
            // is an app that upgraded without publishing the migration; the
            // record itself is still saved and correct.
            Log::warning('[Parity] Deferred reference store unavailable', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($awaiting as $deferred) {
            $mapper = $registry->forLexicon($deferred->collection);

            // The mapper went away (unregistered collection) — drop it rather
            // than leaving it parked forever.
            if (! $mapper) {
                $store->release($deferred->referenceUri);

                continue;
            }

            try {
                $recordClass = $mapper->recordClass();
                $mapper->upsert(
                    $recordClass::fromArray(
                        app(UpcasterChain::class)->upcast($mapper->lexicon(), $deferred->record)
                    ),
                    $deferred->meta()
                );
            } catch (\Throwable $e) {
                // Leave it parked: a malformed body or a transient failure should
                // not consume the reference. The TTL sweep is the backstop.
                Log::warning('[Parity] Deferred reference replay failed', [
                    'reference_uri' => $deferred->referenceUri,
                    'target_uri' => $deferred->targetUri,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $store->release($deferred->referenceUri);

            event(new DeferredReferenceResolved($deferred->referenceUri, $deferred->targetUri));
        }
    }

    public function deleteByUri(string $uri): bool
    {
        $model = $this->findByUri($uri);

        if ($model) {
            return (bool) $model->delete();
        }

        return false;
    }

    /**
     * Attributes a mapper derives from the event meta rather than the record body.
     *
     * These are filled, so they respect the model's mass-assignment rules like any
     * other attribute built from an untrusted record.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function applyMeta(array $attributes, array $meta): array
    {
        return $attributes;
    }

    /**
     * Write the protocol metadata columns onto the model.
     *
     * FIX: deliberately not filled. These columns are the package's own bookkeeping,
     * not data from the record, so a model with a real `$fillable` would silently
     * drop them: a missing uri makes `findByUri()` miss and the next event insert a
     * duplicate row, and a missing cid disables the unchanged-record write guard.
     * The outbound path has always written them directly.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function applyMetaColumns(Model $model, array $meta): void
    {
        if (isset($meta['uri'])) {
            $model->setAttribute($this->uriColumn(), $meta['uri']);
        }

        if (isset($meta['cid'])) {
            $model->setAttribute($this->cidColumn(), $meta['cid']);
        }

        if (isset($meta['rkey']) && ($rkeyColumn = $this->rkeyColumn())) {
            $model->setAttribute($rkeyColumn, $meta['rkey']);
        }

        $model->setAttribute($this->syncedAtColumn(), now());
    }

    /**
     * Define blob fields in the record.
     * Override to specify which fields contain blobs.
     *
     * @return array<string, array{type: 'single'|'array', path?: string}>
     */
    public function blobFields(): array
    {
        return [];
    }

    /**
     * Extract blob references from a record.
     *
     * @return array<BlobReference>
     */
    public function extractBlobs(Data $record): array
    {
        $blobs = [];
        $fields = $this->blobFields();

        if (empty($fields)) {
            return $blobs;
        }

        $recordData = $record->toArray();

        foreach ($fields as $field => $config) {
            $path = $config['path'] ?? $field;
            $value = data_get($recordData, $path);

            if ($config['type'] === 'array' && is_array($value)) {
                foreach ($value as $item) {
                    if ($ref = $this->toBlobReference($item)) {
                        $blobs[] = $ref;
                    }
                }
            } elseif ($ref = $this->toBlobReference($value)) {
                $blobs[] = $ref;
            }
        }

        return $blobs;
    }

    /**
     * Convert array data to BlobReference.
     */
    protected function toBlobReference(mixed $data): ?BlobReference
    {
        if ($data instanceof BlobReference) {
            return $data;
        }

        if (is_array($data) && isset($data['$type']) && $data['$type'] === 'blob') {
            return BlobReference::fromArray($data);
        }

        // Handle nested blob format (e.g., image.blob)
        if (is_array($data) && isset($data['ref'])) {
            return new BlobReference(
                ref: is_array($data['ref']) ? ($data['ref']['$link'] ?? $data['ref']) : $data['ref'],
                mimeType: $data['mimeType'] ?? 'application/octet-stream',
                size: $data['size'] ?? 0
            );
        }

        return null;
    }

    /**
     * Check if this mapper has blob fields defined.
     */
    public function hasBlobFields(): bool
    {
        return ! empty($this->blobFields());
    }
}
