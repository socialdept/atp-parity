<?php

namespace SocialDept\AtpParity\Support;

/**
 * Every blob a value refers to, as full blob objects.
 *
 * A PDS sweeps blobs no record references, and blobs inside overflowed content
 * are invisible to it. Full objects rather than links: a bare `{"$link": ...}`
 * is not a blob, so a list of those holds nothing open.
 */
final class BlobReferences
{
    /**
     * @return array<int, array<string, mixed>> deduplicated, ordered by CID
     */
    public static function collect(mixed $value): array
    {
        $found = [];

        self::walk($value, $found);

        // INFO: ordered by CID so the same content always produces the same
        // record. Traversal order would re-hash on an unrelated edit.
        ksort($found);

        return array_values($found);
    }

    /**
     * @param  array<string, array<string, mixed>>  $found
     */
    private static function walk(mixed $value, array &$found): void
    {
        if (! is_array($value)) {
            return;
        }

        $cid = self::blobCid($value);

        if ($cid !== null) {
            $found[$cid] = $value;

            return;
        }

        foreach ($value as $child) {
            self::walk($child, $found);
        }
    }

    /**
     * The CID of a blob, or null when the value is not one.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function blobCid(array $value): ?string
    {
        if (($value['$type'] ?? null) === 'blob') {
            $link = $value['ref']['$link'] ?? null;

            return is_string($link) ? $link : null;
        }

        // The legacy shape. `cid` plus `mimeType` together, so an object
        // carrying an unrelated `cid` is not mistaken for a blob.
        if (is_string($value['cid'] ?? null) && isset($value['mimeType'])) {
            return $value['cid'];
        }

        return null;
    }
}
