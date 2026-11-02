<?php

namespace SocialDept\AtpParity\Enums;

/**
 * What {@see \SocialDept\AtpParity\Sync\RecordApplier} did with an incoming record,
 * or under `preview()`, what it would do.
 */
enum ApplyOutcome: string
{
    /**
     * No mapper is registered for the collection.
     */
    case Unmapped = 'unmapped';

    case Created = 'created';

    case Updated = 'updated';

    case Deleted = 'deleted';

    /**
     * A delete for a record no model holds.
     */
    case Missing = 'missing';

    /**
     * The stored CID already matches, usually our own write echoing back.
     */
    case Unchanged = 'unchanged';

    /**
     * A backfilled event for a record we already hold, which must not overwrite it.
     */
    case BackfillHeld = 'backfill-held';

    /**
     * The mapper declined it: its gate refused, or a reference's target is not here yet.
     */
    case Refused = 'refused';

    /**
     * A create or update that carried no record body.
     */
    case Empty = 'empty';

    /**
     * The record could not be built into the mapper's record class.
     */
    case Unconstructable = 'unconstructable';

    /**
     * The record failed lexicon validation.
     */
    case Invalid = 'invalid';

    /**
     * Under `preview()` only: the record conflicts with local changes, and the
     * strategy was not run because resolving one writes.
     */
    case Conflict = 'conflict';

    /**
     * The conflict waits for manual resolution and nothing was applied.
     */
    case ConflictPending = 'conflict-pending';

    case ConflictResolved = 'conflict-resolved';
}
