# Changelog

All notable changes to `atp-parity` will be documented in this file.

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
