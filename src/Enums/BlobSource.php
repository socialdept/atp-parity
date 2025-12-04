<?php

namespace SocialDept\AtpParity\Enums;

/**
 * Origin of a blob mapping.
 */
enum BlobSource: string
{
    /**
     * Blob was downloaded from AT Protocol.
     */
    case Remote = 'remote';

    /**
     * Blob was uploaded from local storage.
     */
    case Local = 'local';
}
