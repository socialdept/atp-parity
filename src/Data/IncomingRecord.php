<?php

namespace SocialDept\AtpParity\Data;

/**
 * One inbound record operation, independent of the transport that delivered it.
 *
 * A firehose commit, a Jetstream event and an index batch all reduce to this, so
 * {@see \SocialDept\AtpParity\Sync\RecordApplier} decides the same way whichever
 * of them is feeding it.
 */
final readonly class IncomingRecord
{
    /**
     * @param  array<string, mixed>|null  $record  The record body, null for a delete
     * @param  bool  $backfill  Historical replay rather than a live change. Unknown means live
     */
    public function __construct(
        public string $did,
        public string $collection,
        public string $rkey,
        public ?string $cid = null,
        public ?array $record = null,
        public bool $backfill = false,
        public bool $delete = false,
    ) {
    }

    public static function deletion(string $did, string $collection, string $rkey, bool $backfill = false): self
    {
        return new self($did, $collection, $rkey, backfill: $backfill, delete: true);
    }

    public function uri(): string
    {
        return "at://{$this->did}/{$this->collection}/{$this->rkey}";
    }
}
