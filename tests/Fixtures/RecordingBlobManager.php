<?php

namespace SocialDept\AtpParity\Tests\Fixtures;

use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpSchema\Data\BlobReference;

/**
 * Records what a mapper tried to upload instead of reaching a PDS.
 */
class RecordingBlobManager extends BlobManager
{
    /** Real CIDs, so anything that re-measures the record can still decode them. */
    private const CIDS = [
        'bafyreicwr652hmepqildzri7dbze5hyykve4lw2lbxbvqk6yqhsahwgsma',
        'bafyreihy4in5i5ranrm7nifx7ef2xs26mj5inbkxrhqrvyzthwk7e5owce',
    ];

    /** @var array<int, array{did: string, content: string, mimeType: string}> */
    public array $uploads = [];

    public function __construct()
    {
        // Deliberately does not call the parent: nothing here touches storage.
    }

    public function uploadFromContent(string $did, string $content, string $mimeType): BlobReference
    {
        $this->uploads[] = ['did' => $did, 'content' => $content, 'mimeType' => $mimeType];

        return new BlobReference(
            ref: self::CIDS[(count($this->uploads) - 1) % count(self::CIDS)],
            mimeType: $mimeType,
            size: strlen($content),
        );
    }
}
