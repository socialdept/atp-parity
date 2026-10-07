# Changelog

All notable changes to `atp-parity` will be documented in this file.

## v1.1.1

### Fixed
- **Applying an inbound record wrote it back to the repo.** The save that applies a
  record fired `updated`, so a model using `AutoSyncsWithAtp` or
  `AutoSyncsWithReference` resynced inside the signal handler, before the mapper's
  `afterUpsert()` had landed the rest of the content. An echo of our own write became a
  second write, and a genuine remote edit was overwritten with stale content, then
  written again by anything `afterUpsert()` saved. Every inbound path now runs inside
  `AutoSync::without()`, `afterUpsert()` included.
  The one write that still happens is the model's own reference record: a main record
  that changed remotely is followed by a resync of its reference alone, so the StrongRef
  points at the new CID. Its unchanged guard skips the write when the CID did not move.

- **A returned CID could fail to reach the row.** The metadata writers set attributes
  and saved, and a save writes only what differs from the instance's original. A stale
  instance receiving the CID it started with wrote nothing, leaving the row on a CID
  the repo no longer held. `SyncService` and `ReferenceSyncService` now write these
  columns with a direct update keyed on the model. Timestamps and event behavior are
  unchanged.

- **A failed reference deleted a live main record.** With `rollback_on_failure`,
  `syncWithReference()` unsynced the main record whenever the reference failed, including
  when the main record already existed and had only been updated or left unchanged. Only
  a main record the same call created is rolled back now.

- **The echo guard missed reference records.** `ParitySignal` looked a model up by the
  main URI column and compared the main CID column, where a reference record's own URI
  and CID never are. For a reference mapper it now uses the reference columns, so an
  echo with an unchanged CID is skipped, and a backfilled reference we already hold is
  no longer re-applied.

- **A skipped resync still uploaded its overflow blob.** Overflow ran while the record
  was built, before the unchanged-write guard. The guard now decides on a draft whose
  blob is addressed locally, and only a real write uploads.

### Added
- **`AutoSync::without(callable)`** runs a callback with every auto-sync trait
  suppressed, for app code that mirrors the repo rather than changing it. Nests safely
  and ends on return or exception. `AutoSync::isSuppressed()` reports the state.
- **`RecordMapper::withoutOverflowUploads(callable)`** builds records whose overflow
  blobs are addressed locally instead of uploaded.
- **`BlobCid`**, the CID a PDS assigns a blob's bytes.

### Tests
- An update or create event with no record body, the shape of a superseded event, is
  pinned as skipped without an upsert or an exception.

## v1.1.0

### Added
- **A field can overflow to a blob.** `Field::overflowsToBlob()` declares where a
  field's value goes once the encoded record exceeds a threshold, and the record is
  read back the same way on import. A record has a hard ceiling of 1 MiB
  (`MAX_CBOR_RECORD_SIZE`) and the guidance is to stay within a few dozen KBytes, which
  a mapper could not honor on its own: it has no way to know how large the record it
  contributes to has become.

  Opt in. A field that does not declare it never touches the blob path, and a mapper
  declaring none behaves exactly as before. `PARITY_RECORD_OVERFLOW_BYTES` sets the
  default threshold.

  Pass `references` wherever the value can contain blobs. A PDS collects a blob no
  record references, and a blob nested inside overflowed content is invisible to it,
  so a long record loses its images some time after the write with nothing at the time
  to show for it. `BlobReferences::collect()` gathers the full blob objects,
  deduplicated and ordered by CID so the same content always produces the same record.

- **`RecordSize`**, the dag-cbor byte size of a record as a PDS stores it, including
  the `$type` a server adds before hashing. Measuring the JSON form a record travels in
  counts a link as a map of one string and overstates every record carrying blobs,
  which is the class of record whose size decides anything.

- **`RecordMapper::withoutOverflow()`**, for callers measuring the inline size. Without
  it, every size check would upload a blob and no record could read as over the limit.

- **`DataModel`**, the JSON-form to data-model conversion extracted from `RecordCid` so
  hashing and measuring agree on what a record is. `RecordCid` behavior is unchanged.

### Fixed
- **A declared date field decoded to an array.** `FieldMap::plain()` called `toArray()`
  on any object, and Carbon's returns the date parts rather than a date, so the value
  reached a date cast as an array and threw. A `DateTimeInterface` now passes through.

## v1.0.1

### Fixed
- **The skip-unchanged guard was inert on the 1.0 line.** The same defect fixed in
  v0.6.2: `SyncService` and `ReferenceSyncService` hashed `Data::toArray()`, which omits
  the top-level `$type`. A PDS adds `$type` itself before hashing, so a stored CID is
  always the address of the `$type`-bearing record and the comparison could not match
  for any record ever written. v1.0.0 branched without the v0.6.2 fix and so shipped
  with the guard disabled, which means every resync wrote. Both guards now hash
  `Data::toRecord()`.

  **Anyone on v1.0.0 should move to this release.** The guard there does nothing.

### Added
- **`$force` on the sync-or-create path.** `syncWithReference()`, `syncReferenceOnly()`
  and `syncAsWithMapper()` take `bool $force = false` and thread it to the `resync*`
  call they delegate to for an already-synced model. Operator-facing surfaces call
  these rather than a `resync*`, so with the guard working a Resync pressed on a
  byte-identical record would report success and write nothing.

  Every new parameter defaults to false, which is the previous behavior.

## v1.0.0

A mapper declares its fields instead of writing both directions, an ingest boundary
refuses what it has not allowed, records of older shapes are brought forward before
anything reads them, and blob uploads leave record construction.

**See UPGRADING.md. Three changes need action.**

### Changed
- **A mapper imports nothing until it declares `accepts()`.** Ingest is default deny. A
  mapper that overrides `shouldImport()` itself is unaffected, which is most existing
  mappers.
- **`SchemaMapper` takes an acceptance argument.**
- **Protocol metadata is written with `setAttribute()` rather than filled**, so a model
  with a real `$fillable` no longer silently loses its `uri` and `cid`. If you override
  `applyMeta()`, the metadata columns moved to `applyMetaColumns()`, and calling
  `parent::applyMeta()` still works.

### Added
- **`fields()` declarations.** A mapper returns a map of record path to `Field` and the
  package derives both directions from it, rather than two hand-written halves that
  nothing checks agree. Brings `Field`, `RecordCodec`, and `recordColumns()` with the
  resync gate it enables: a save only pushes when a column the record depends on changed.
- **`Acceptance`**, with `connectedActors()` and `knownActors()`.
- **Record upcasting**, so a record written in an older shape is brought forward before
  anything reads it.
- **`AssertsRecordParity`** test assertions.
- **Blob resolution before construction**, so record construction stays free of I/O.

## v0.6.2

### Fixed
- **The skip-unchanged guard never fired.** `SyncService` and `ReferenceSyncService`
  hashed `Data::toArray()`, which omits the top-level `$type`. A PDS adds `$type`
  itself before hashing, so a stored CID is always the address of the
  `$type`-bearing record and the comparison could not match for any record ever
  written. Every resync wrote, which is the behaviour v0.6.0 was released to stop.
  Both guards now hash `Data::toRecord()`.

  The suite could not catch this: each test minted its fake PDS response with the
  same `toArray()` expression the guard used, so the error cancelled out on both
  sides. Those now mint from `toRecord()`, which is what a PDS returns, and
  `RecordCidTest` pins `$type` as part of a record's address.

### Added
- **`$force` on the sync-or-create path.** `syncWithReference()`,
  `syncReferenceOnly()` and `syncAsWithMapper()` now take `bool $force = false`
  and thread it to the `resync*` call they delegate to for an already-synced
  model. Operator-facing surfaces call these rather than a `resync*`, so a force
  that stopped at the resync methods could not be reached from the one place a
  human presses "Resync". Default `false`, so existing callers are unchanged.

## v0.6.1

### Fixed
- **`resyncWithReference()` ignored `$force`.** v0.6.0 added the flag to `resync()`,
  `resyncWithMapper()` and `resyncReference()`, but not to the combined entry point a
  repair tool uses for a model carrying a reference record. A forced resync of such a
  model skipped both writes whenever the repo already held them, which is exactly the
  case a repair is pressed for.

## v0.6.0

### Added
- **A resync skips the write when the repo already holds the record byte for byte.**
  The CID the record would have is compared against the stored `atp_cid` and the
  `putRecord` is skipped when they match, so a sync wired to model saves no longer
  writes unchanged records into an author's repo.

  Additive and backwards compatible:
  - `SyncResult` gains an `unchanged` flag and an `unchanged()` constructor. It reports
    success, so callers checking `isSuccess()` need no change.
  - `resync()`, `resyncWithMapper()` and `resyncReference()` gain an optional `$force`.
  - `PARITY_SYNC_SKIP_UNCHANGED` disables the comparison from env.

  The guard did not actually fire until v0.6.2 on this line, and until v1.0.1 on the
  1.0 line. See those entries.

### Changed
- **Declares `socialdept/atp-cbor` directly**, which until now was reached only through
  `atp-signals`.

## v0.5.0

### Added
- **Backfill gating.** A replayed historical event no longer overwrites a record
  that already exists locally. The "CID unchanged" check cannot catch this: a
  replay carries the record's *old* CID, which never matches the latest one
  stored, so every past version was re-applied in turn — and under the default
  `remote` conflict strategy, applied over newer local edits. Configure with
  `atp-parity.backfill.overwrites_existing` (`PARITY_BACKFILL_OVERWRITES_EXISTING`),
  default `false`.
- `$meta['backfill']` is now passed to mappers, so an app can tell a replayed
  historical event from a live commit.
- **`RecordMapper::afterUpsert($model, $record, $meta, $created)`** — a hook that
  runs once the model is persisted. `recordToAttributes()` can only describe
  columns on the row itself, so a record whose content belongs in related tables
  (revisions, snapshots, translations) had nowhere to put it and was silently
  dropped by `fill()`. No-op by default.

- **`Contracts\ResolvesConflictStrategy`** — an opt-in interface letting a mapper
  choose the conflict strategy per record. The configured strategy is global, but
  which side should win is often a property of the individual record. Mappers that
  do not implement it keep using `atp-parity.conflicts.strategy`.

- **`ConflictResolved`** — dispatched whenever a conflict is resolved, under
  every strategy. `ConflictDetected` only fires for `manual`, so the strategies
  that silently discard one side left no trace at all, and an application cannot
  notice a wrong policy it is never told about.
- **`RecordConstructionFailed`** — dispatched when an inbound record cannot be
  built into its DTO and is dropped. Previously log-only: the record never
  reaches a mapper, the cursor still advances, and a malformed lexicon field can
  discard an entire collection while every other health signal reads normal.

### Changed
- **Requires `socialdept/atp-signals ^2.1`** (was `^2.0`). `SignalEvent::$backfill`
  was introduced in 2.1.0; on 2.0.x it does not exist and the gate cannot work.

## v0.4.10

### Added
- **Inbound handling for reference records.** `ReferenceRecordMapper::upsert()`
  previously had no inbound path at all — a reference record arriving from the
  network was parsed and dropped. It now resolves the record it points at, by
  the referenced URI or by the reference's own URI, and stamps
  `atp_reference_uri` / `atp_reference_cid` onto that model.
- **Deferred references.** A reference whose target has not arrived yet is
  parked rather than refused. Refusing is data loss, not a replay: a mapper
  returning false skips one record inside a batch the consumer still answers
  200 to, so the cursor advances past it. `DeferredReferenceStore` (contract),
  `DatabaseDeferredReferenceStore` (driver), the `DeferredReference` DTO, the
  `create_parity_deferred_references_table` migration, and
  `DeferredReferenceParked` / `Resolved` / `Expired` events. `RecordMapper`
  replays whatever is waiting after a **create** succeeds; updates skip it,
  since a reference for an existing target would have applied directly.
  Configure under `atp-parity.deferred_references`.
- `parity:prune-deferred` — age out orphans past the TTL (7 days by default),
  dry-run unless `--execute`. Emits an event per expiry so an entry aging out is
  observable rather than a number quietly going down.
- **`atp-parity.columns.rkey`** — when set, imports store the record's real
  rkey. Apps that assign an rkey locally before a record exists remotely
  otherwise hold a value the repo has never had, which matters as soon as
  anything routes or reconciles by rkey. Null (off) by default.
- A publish tag for the deferred-references migration,
  `parity-migrations-deferred-references`.

### Changed
- `RecordMapper::upsert()` resolves the existing model **before** calling
  `shouldImport()` and passes it as `$meta['existing']`, so a mapper can tell a
  create from an update without querying for itself. Passed through meta rather
  than as a new parameter: widening the signature is a BC break for every
  mapper that overrides it.

### Fixed
- `ParitySignal` debug logging read `config('signal.debug')`, a v1 key that
  always resolved to null, so none of the `[Parity:Signal]` traces ever fired —
  including with `SIGNAL_DEBUG=true`. It now reads `atp-signals.debug`.

## v0.4.9

### Fixed
- Reference-record sync failures are no longer silently swallowed. When the main
  record syncs but the reference write fails, `resyncWithReference()` /
  `syncWithReference()` now return a result whose `hasReferenceFailure()` is true
  (with the error on `referenceError`) instead of reporting a clean success with
  a stale reference CID. `isFullySynced()` returns false in that state.
- `AutoSyncsWithReference` now inspects the sync result and captures a pending
  sync (for retry) when the reference leg fails, matching the behaviour that was
  previously reachable only via auth exceptions.
- `PendingSyncManager` retries of reference operations no longer treat a
  reference-leg failure as a success, so a stale reference is retried instead of
  being dropped.

### Added
- `ReferenceSyncFailed` event, dispatched when a reference-record write fails
  (carries the model, error message, and the reference URI when known). Every
  caught reference failure is also logged at warning level, so failures are
  observable even when the pending-sync system is disabled.
- `ReferenceSyncResult::referenceFailed()` and `hasReferenceFailure()` /
  `referenceError` for distinguishing a failed reference leg from a clean sync.
