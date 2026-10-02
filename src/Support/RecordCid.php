<?php

namespace SocialDept\AtpParity\Support;

use SocialDept\AtpCbor\Core\CID;
use Throwable;

/**
 * The CID a record would have once a PDS stores it.
 *
 * Exists so a sync can tell "this record is already in the repo, byte for byte"
 * without reading it back. The PDS addresses a record by the hash of its
 * dag-cbor encoding, so computing that locally and comparing it to the CID we
 * recorded last time answers the question exactly and offline.
 *
 * The subtlety is that a record in transit is in the **JSON** form of the data
 * model, where a link is a one-key `{"$link": "..."}` object and bytes are
 * `{"$bytes": "<base64>"}`. dag-cbor has native types for both, so encoding the
 * JSON form verbatim hashes a map where the PDS hashed a tag-42 link, and the
 * CID never matches. That failure is silent and safe in the wrong direction: the
 * comparison simply never fires, so the guard appears to work while protecting
 * nothing. {@see \SocialDept\AtpParity\Tests\Unit\Support\RecordCidTest} pins it
 * against a record read from a live PDS.
 */
final class RecordCid
{
    /**
     * @param  array<string, mixed>  $record
     */
    public static function for(array $record): ?string
    {
        try {
            return CID::forDagCbor(self::toDataModel($record))->toString();
        } catch (Throwable) {
            // A record we cannot address is a record we cannot prove unchanged,
            // and the caller must then write rather than assume.
            return null;
        }
    }

    /**
     * Rebuild the native dag-cbor types the JSON form encodes as objects.
     */
    private static function toDataModel(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (self::isLink($value)) {
            return CID::fromString($value['$link']);
        }

        if (self::isBytes($value)) {
            // Strict mode: a payload that is not really base64 must surface as a
            // failure above rather than silently hash as an empty string.
            $decoded = base64_decode($value['$bytes'], true);

            if ($decoded === false) {
                throw new \UnexpectedValueException('Record contains a $bytes value that is not valid base64.');
            }

            return $decoded;
        }

        return array_map([self::class, 'toDataModel'], $value);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function isLink(array $value): bool
    {
        return count($value) === 1 && isset($value['$link']) && is_string($value['$link']);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function isBytes(array $value): bool
    {
        return count($value) === 1 && array_key_exists('$bytes', $value) && is_string($value['$bytes']);
    }
}
