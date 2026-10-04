<?php

namespace SocialDept\AtpParity\Support;

use SocialDept\AtpCbor\Core\CID;
use UnexpectedValueException;

/**
 * The atproto data model, rebuilt from the JSON form a record travels in.
 *
 * The JSON form writes a link as `{"$link": "..."}` and bytes as
 * `{"$bytes": "<base64>"}`. dag-cbor has native types for both, so encoding the
 * JSON form verbatim hashes and measures a different value than a PDS stores.
 */
final class DataModel
{
    /**
     * @throws UnexpectedValueException when a `$bytes` payload is not base64
     */
    public static function fromJsonForm(mixed $value): mixed
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
                throw new UnexpectedValueException('Record contains a $bytes value that is not valid base64.');
            }

            return $decoded;
        }

        return array_map([self::class, 'fromJsonForm'], $value);
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
