<?php

namespace SocialDept\AtpParity\Import;

/**
 * Immutable value object representing the result of an import operation.
 */
readonly class ImportResult
{
    public function __construct(
        public string $did,
        public string $collection,
        public int $recordsSynced,
        public int $recordsSkipped,
        public int $recordsFailed,
        public bool $completed,
        public ?string $cursor = null,
        public ?string $error = null,
    ) {}

    /**
     * Check if the import completed successfully.
     */
    public function isSuccess(): bool
    {
        return $this->completed && $this->error === null;
    }

    /**
     * Check if the import was partially completed.
     */
    public function isPartial(): bool
    {
        return ! $this->completed && $this->recordsSynced > 0;
    }

    /**
     * Check if the import failed.
     */
    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Get total records processed.
     */
    public function totalProcessed(): int
    {
        return $this->recordsSynced + $this->recordsSkipped + $this->recordsFailed;
    }

    /**
     * Create a successful result.
     */
    public static function success(string $did, string $collection, int $synced, int $skipped = 0, int $failed = 0): self
    {
        return new self(
            did: $did,
            collection: $collection,
            recordsSynced: $synced,
            recordsSkipped: $skipped,
            recordsFailed: $failed,
            completed: true,
        );
    }

    /**
     * Create a partial result (incomplete).
     */
    public static function partial(string $did, string $collection, int $synced, string $cursor, int $skipped = 0, int $failed = 0): self
    {
        return new self(
            did: $did,
            collection: $collection,
            recordsSynced: $synced,
            recordsSkipped: $skipped,
            recordsFailed: $failed,
            completed: false,
            cursor: $cursor,
        );
    }

    /**
     * Create a failed result.
     */
    public static function failed(string $did, string $collection, string $error, int $synced = 0, int $skipped = 0, int $failed = 0, ?string $cursor = null): self
    {
        return new self(
            did: $did,
            collection: $collection,
            recordsSynced: $synced,
            recordsSkipped: $skipped,
            recordsFailed: $failed,
            completed: false,
            cursor: $cursor,
            error: $error,
        );
    }

    /**
     * Merge multiple results for the same DID into one aggregate result.
     *
     * @param  ImportResult[]  $results
     */
    public static function aggregate(string $did, array $results): self
    {
        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];
        $allCompleted = true;

        foreach ($results as $result) {
            $synced += $result->recordsSynced;
            $skipped += $result->recordsSkipped;
            $failed += $result->recordsFailed;

            if (! $result->completed) {
                $allCompleted = false;
            }

            if ($result->error) {
                $errors[] = "{$result->collection}: {$result->error}";
            }
        }

        return new self(
            did: $did,
            collection: '*',
            recordsSynced: $synced,
            recordsSkipped: $skipped,
            recordsFailed: $failed,
            completed: $allCompleted,
            error: $errors ? implode('; ', $errors) : null,
        );
    }
}
