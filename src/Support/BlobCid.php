<?php

namespace SocialDept\AtpParity\Support;

use SocialDept\AtpCbor\Core\CID;

/**
 * The CID a PDS gives a blob: CIDv1, raw codec, sha2-256 of the bytes.
 *
 * Lets a record that references a blob be built and hashed before the blob is
 * uploaded, so the unchanged-write guard can decide first.
 */
final class BlobCid
{
    private const RAW_CODEC = 0x55;

    public static function for(string $bytes): string
    {
        return (new CID(
            version: 1,
            codec: self::RAW_CODEC,
            hash: chr(0x12).chr(0x20).hash('sha256', $bytes, true),
        ))->toString();
    }
}
