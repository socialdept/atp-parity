<?php

namespace SocialDept\AtpParity\Enums;

/**
 * Determines how blobs are stored and tracked locally.
 */
enum BlobStorageDriver: string
{
    /**
     * Store blobs on Laravel filesystem with BlobMapping table for tracking.
     * Requires running parity migrations.
     */
    case Filesystem = 'filesystem';

    /**
     * Store blobs via Spatie MediaLibrary.
     * No parity_blob_mappings table required.
     * Models must use InteractsWithMediaLibrary trait.
     */
    case MediaLibrary = 'medialibrary';
}
