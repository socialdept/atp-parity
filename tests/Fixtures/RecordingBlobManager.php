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

    /** Content keyed by CID, for downloads. */
    public array $stored = [];

    /** When set, a download throws instead of returning. */
    public ?string $downloadFailure = null;

    public function downloadContent(BlobReference $blob, string $did): string
    {
        if ($this->downloadFailure !== null) {
            throw new \RuntimeException($this->downloadFailure);
        }

        return $this->stored[$blob->ref] ?? throw new \RuntimeException('No blob stored for '.$blob->ref);
    }

    public function uploadFromContent(string $did, string $content, string $mimeType): BlobReference
    {
        $this->uploads[] = ['did' => $did, 'content' => $content, 'mimeType' => $mimeType];

        $cid = self::CIDS[(count($this->uploads) - 1) % count(self::CIDS)];
        $this->stored[$cid] = $content;

        return new BlobReference(ref: $cid, mimeType: $mimeType, size: strlen($content));
    }
}
