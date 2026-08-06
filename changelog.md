# Changelog

All notable changes to `atp-parity` will be documented in this file.

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
