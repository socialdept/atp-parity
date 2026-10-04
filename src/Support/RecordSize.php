<?php

namespace SocialDept\AtpParity\Support;

use SocialDept\AtpCbor\Core\CBOR;
use Throwable;

/**
 * The dag-cbor byte size of a record, as a PDS stores it.
 *
 * `MAX_CBOR_RECORD_SIZE` is 1 MiB. The threshold a mapper overflows at is a
 * policy choice well below that, so it is configured rather than constant here.
 */
final class RecordSize
{
    /**
     * Null when the record cannot be encoded, so a caller never reads a failure
     * as a small record.
     *
     * @param  array<string, mixed>  $record
     */
    public static function of(array $record): ?int
    {
        try {
            return strlen(CBOR::encode(DataModel::fromJsonForm($record)));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function exceeds(array $record, int $limit): bool
    {
        return (self::of($record) ?? 0) > $limit;
    }
}
