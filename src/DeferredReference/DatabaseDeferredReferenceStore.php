<?php

namespace SocialDept\AtpParity\DeferredReference;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Data\DeferredReference;

/**
 * Durable store, and the right default: parked references must survive a queue
 * worker restart, since the delivering consumer has already acked them and no
 * redelivery is coming.
 */
class DatabaseDeferredReferenceStore implements DeferredReferenceStore
{
    public function park(DeferredReference $reference): void
    {
        // Upsert on the reference URI: an orphan can be re-delivered (at-least-once
        // delivery, or a cursor rewind) and must not accumulate duplicates.
        $this->query()->updateOrInsert(
            ['reference_uri' => $reference->referenceUri],
            $reference->toArray() + ['updated_at' => now(), 'created_at' => now()],
        );
    }

    public function awaiting(string $targetUri): array
    {
        return $this->query()
            ->where('target_uri', $targetUri)
            ->orderBy('parked_at')
            ->get()
            ->map(fn ($row) => DeferredReference::fromArray((array) $row))
            ->all();
    }

    public function release(string $referenceUri): void
    {
        $this->query()->where('reference_uri', $referenceUri)->delete();
    }

    public function releaseFor(string $targetUri): int
    {
        return $this->query()->where('target_uri', $targetUri)->delete();
    }

    public function prune(DateTimeInterface $before): array
    {
        $expired = $this->query()
            ->where('parked_at', '<', $before)
            ->get()
            ->map(fn ($row) => DeferredReference::fromArray((array) $row))
            ->all();

        if ($expired !== []) {
            $this->query()
                ->whereIn('reference_uri', array_map(fn ($r) => $r->referenceUri, $expired))
                ->delete();
        }

        return $expired;
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    protected function query(): \Illuminate\Database\Query\Builder
    {
        return DB::connection(config('atp-parity.deferred_references.connection'))
            ->table(config('atp-parity.deferred_references.table', 'parity_deferred_references'));
    }
}
