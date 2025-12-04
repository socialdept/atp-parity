<?php

namespace SocialDept\AtpParity\Enums;

/**
 * Strategy for generating blob URLs.
 */
enum BlobUrlStrategy: string
{
    /**
     * Serve blobs from local storage.
     */
    case Local = 'local';

    /**
     * Use Bluesky CDN URLs (fastest, recommended for Bluesky content).
     */
    case Cdn = 'cdn';

    /**
     * Use direct PDS getBlob endpoint.
     */
    case Pds = 'pds';
}
