<?php

namespace SocialDept\AtpParity\Data;

use DateTimeImmutable;

/**
 * A reference record whose target has not arrived yet.
 *
 * Reference records carry no content of their own — only a strong-ref at a main
 * record — so they are only actionable once that main record exists locally.
 * Delivery order is not guaranteed: a producer may write the reference first,
 * and even when it does not, the two can land in different batches.
 *
 * Dropping one is silent data loss, because the delivering consumer has already
 * acknowledged the event and its cursor has moved on. So an orphan is parked
 * here and replayed when its target is created.
 */
readonly class DeferredReference
{
    /**
     * @param  string  $referenceUri  The reference record's own AT-URI.
     * @param  string  $targetUri  The main record it points at — the index.
     * @param  string  $collection  NSID of the reference record, used to pick the mapper on replay.
     * @param  array<string, mixed>  $record  Raw record body, replayed verbatim.
     */
    public function __construct(
        public string $referenceUri,
        public string $targetUri,
        public string $collection,
        public string $did,
        public ?string $cid,
        public array $record,
        public DateTimeImmutable $parkedAt,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(
            referenceUri: $attributes['reference_uri'],
            targetUri: $attributes['target_uri'],
            collection: $attributes['collection'],
            did: $attributes['did'],
            cid: $attributes['cid'] ?? null,
            record: is_string($attributes['record'] ?? null)
                ? (json_decode($attributes['record'], true) ?: [])
                : ($attributes['record'] ?? []),
            parkedAt: new DateTimeImmutable($attributes['parked_at'] ?? 'now'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference_uri' => $this->referenceUri,
            'target_uri' => $this->targetUri,
            'collection' => $this->collection,
            'did' => $this->did,
            'cid' => $this->cid,
            'record' => json_encode($this->record),
            'parked_at' => $this->parkedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The meta array the mapper expects on replay, identical in shape to what a
     * live delivery would have produced.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'uri' => $this->referenceUri,
            'cid' => $this->cid,
            'did' => $this->did,
            'rkey' => basename($this->referenceUri),
        ];
    }
}
