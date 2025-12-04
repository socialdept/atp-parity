<?php

namespace SocialDept\AtpParity\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SocialDept\AtpParity\Blob\BlobMapping;
use SocialDept\AtpSchema\Data\BlobReference;

class BlobUploaded
{
    use Dispatchable;

    public function __construct(
        public readonly BlobMapping $mapping,
        public readonly BlobReference $blob,
    ) {}
}
