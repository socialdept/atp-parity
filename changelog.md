# Changelog

All notable changes to `atp-parity` will be documented in this file.

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
