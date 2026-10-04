<?php

namespace SocialDept\AtpParity\Tests\Unit\Support;

use SocialDept\AtpParity\Support\BlobReferences;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * Content moved into a blob takes its images out of the PDS's view.
 *
 * The sweep happens long after the write, so nothing at publish time shows that
 * a long post is about to lose its pictures.
 */
class BlobReferencesTest extends TestCase
{
    private const ALPHA = 'bafyreiasqx6lkeilrygjtklpw36tu5ranufbi3in3udvh3yofhbnlpyrkm';

    private const BETA = 'bafyreia3qiqwrrksia3cmmsa3oxb4xknd5vfctau7aoriscb7fktny7dzq';

    private function blob(string $cid, string $mime = 'image/jpeg'): array
    {
        return ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => $mime, 'size' => 10];
    }

    public function test_it_finds_blobs_at_any_depth(): void
    {
        $content = ['items' => [
            ['type' => 'text', 'text' => 'hello'],
            ['type' => 'image', 'image' => $this->blob(self::ALPHA)],
            ['type' => 'grid', 'images' => [['image' => $this->blob(self::BETA)]]],
        ]];

        $found = BlobReferences::collect($content);

        $this->assertCount(2, $found);
        $this->assertSame([self::BETA, self::ALPHA], array_column(array_column($found, 'ref'), '$link'));
    }

    /** A bare `{"$link": ...}` is not a blob, so a list of those holds nothing open. */
    public function test_it_keeps_the_whole_blob_object(): void
    {
        $found = BlobReferences::collect(['image' => $this->blob(self::ALPHA, 'image/png')]);

        $this->assertSame($this->blob(self::ALPHA, 'image/png'), $found[0]);
    }

    /** Traversal order would re-hash the record on an unrelated edit. */
    public function test_it_deduplicates_and_orders_by_cid(): void
    {
        $found = BlobReferences::collect([
            $this->blob(self::BETA),
            $this->blob(self::ALPHA),
            $this->blob(self::BETA),
        ]);

        $this->assertCount(2, $found);
        $this->assertSame([self::BETA, self::ALPHA], array_column(array_column($found, 'ref'), '$link'));
    }

    public function test_it_ignores_an_object_that_merely_carries_a_cid(): void
    {
        $found = BlobReferences::collect(['parent' => ['cid' => self::ALPHA, 'uri' => 'at://x']]);

        $this->assertSame([], $found);
    }

    public function test_it_reads_the_legacy_blob_shape(): void
    {
        $found = BlobReferences::collect(['image' => ['cid' => self::ALPHA, 'mimeType' => 'image/jpeg']]);

        $this->assertCount(1, $found);
    }

    public function test_it_returns_nothing_for_content_without_blobs(): void
    {
        $this->assertSame([], BlobReferences::collect(['items' => [['type' => 'text', 'text' => 'hi']]]));
    }
}
