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
 * model, so it has to be rebuilt before hashing. {@see DataModel} does that, and
 * {@see \SocialDept\AtpParity\Tests\Unit\Support\RecordCidTest} pins the result
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
            return CID::forDagCbor(DataModel::fromJsonForm($record))->toString();
        } catch (Throwable) {
            // A record we cannot address is a record we cannot prove unchanged,
            // and the caller must then write rather than assume.
            return null;
        }
    }
}
