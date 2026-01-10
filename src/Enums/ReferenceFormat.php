<?php

namespace SocialDept\AtpParity\Enums;

/**
 * Format for reference record pointers.
 *
 * Reference records point to "main" records using one of these formats:
 * - AtUri: Simple AT-URI string (at://did/collection/rkey)
 * - StrongRef: Full reference with CID ({uri, cid})
 */
enum ReferenceFormat: string
{
    /**
     * Simple AT-URI string format.
     *
     * Example: "at://did:plc:xyz/app.bsky.feed.post/abc123"
     */
    case AtUri = 'at-uri';

    /**
     * Full strong reference with URI and CID.
     *
     * Example: {"uri": "at://did:plc:xyz/app.bsky.feed.post/abc123", "cid": "bafyre..."}
     */
    case StrongRef = 'strongref';
}
