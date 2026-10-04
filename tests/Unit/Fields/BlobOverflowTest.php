<?php

namespace SocialDept\AtpParity\Tests\Unit\Fields;

use SocialDept\AtpParity\Blob\BlobManager;
use SocialDept\AtpParity\RecordMapper;
use SocialDept\AtpParity\Tests\Fixtures\DeclarativeMapper;
use SocialDept\AtpParity\Tests\Fixtures\OverflowMapper;
use SocialDept\AtpParity\Tests\Fixtures\OverflowRecord;
use SocialDept\AtpParity\Tests\Fixtures\RecordingBlobManager;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A field that stops being written inline once the record no longer fits.
 *
 * Declared on the field so a mapper cannot forget to check its own size before
 * a write.
 */
class BlobOverflowTest extends TestCase
{
    private const IMAGE = 'bafyreiasqx6lkeilrygjtklpw36tu5ranufbi3in3udvh3yofhbnlpyrkm';

    private RecordingBlobManager $blobs;

    private OverflowMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blobs = new RecordingBlobManager();
        $this->app->instance(BlobManager::class, $this->blobs);
        $this->mapper = new OverflowMapper();
    }

    private function model(int $paragraphs, bool $withImage = false): TestModel
    {
        $items = [];

        for ($i = 0; $i < $paragraphs; $i++) {
            $items[] = ['type' => 'text', 'text' => "Paragraph {$i} of a document that keeps going."];
        }

        if ($withImage) {
            $items[] = ['type' => 'image', 'image' => [
                '$type' => 'blob', 'ref' => ['$link' => self::IMAGE], 'mimeType' => 'image/jpeg', 'size' => 1024,
            ]];
        }

        return new TestModel([
            'did' => 'did:plc:tester',
            'title' => 'A document',
            'body' => $items,
        ]);
    }

    public function test_a_record_that_fits_stays_inline(): void
    {
        $data = $this->mapper->toRecord($this->model(1))->toArray();

        $this->assertArrayHasKey('items', $data['content']);
        $this->assertArrayNotHasKey('blob', $data['content']);
        $this->assertSame([], $this->blobs->uploads);
    }

    public function test_a_record_that_does_not_fit_moves_its_content_to_a_blob(): void
    {
        $data = $this->mapper->toRecord($this->model(20))->toArray();

        $this->assertArrayNotHasKey('items', $data['content']);
        $this->assertSame('blob', $data['content']['blob']['$type']);
        $this->assertCount(1, $this->blobs->uploads);
        $this->assertSame('did:plc:tester', $this->blobs->uploads[0]['did']);
        $this->assertSame('text/plain', $this->blobs->uploads[0]['mimeType']);

        // The blob carries the items the record stopped carrying.
        $uploaded = json_decode($this->blobs->uploads[0]['content'], true);
        $this->assertCount(20, $uploaded);
        $this->assertSame('Paragraph 0 of a document that keeps going.', $uploaded[0]['text']);
    }

    /** The image is inside a blob now, so the record must name it or it is swept. */
    public function test_it_relists_the_blobs_the_overflowed_content_used(): void
    {
        $data = $this->mapper->toRecord($this->model(20, withImage: true))->toArray();

        $this->assertSame(self::IMAGE, $data['content']['references'][0]['ref']['$link']);
        $this->assertSame('blob', $data['content']['references'][0]['$type']);
    }

    /** If measurement overflowed too, no record could ever read as too large. */
    public function test_measurement_can_opt_out_of_overflowing(): void
    {
        $data = RecordMapper::withoutOverflow(fn () => $this->mapper->toRecord($this->model(20))->toArray());

        $this->assertArrayHasKey('items', $data['content']);
        $this->assertSame([], $this->blobs->uploads);
    }

    /** No repo means nowhere to put the blob. */
    public function test_a_model_with_no_repo_stays_inline(): void
    {
        $model = $this->model(20);
        unset($model->did);

        $data = $this->mapper->toRecord($model)->toArray();

        $this->assertArrayHasKey('items', $data['content']);
        $this->assertSame([], $this->blobs->uploads);
    }

    /** Opt in only. A mapper that declares no overflow never grows a blob. */
    public function test_a_mapper_that_declares_no_overflow_is_untouched(): void
    {
        $model = new TestModel([
            'did' => 'did:plc:tester',
            'content' => str_repeat('a document that keeps going. ', 500),
            'label' => 'Greeting',
            'mode' => 'fast',
        ]);

        $data = (new DeclarativeMapper())->toRecord($model)->toArray();

        $this->assertArrayHasKey('text', $data);
        $this->assertSame([], $this->blobs->uploads);
    }

    private function resolve(array $data, array $meta = ['did' => 'did:plc:tester']): array
    {
        $method = new \ReflectionMethod($this->mapper, 'resolveOverflow');

        return $method->invoke($this->mapper, OverflowRecord::fromArray($data), $meta)->toArray();
    }

    private function blobbedRecord(): array
    {
        $this->blobs->uploads = [];
        $data = $this->mapper->toRecord($this->model(20, withImage: true))->toArray();
        $this->assertArrayHasKey('blob', $data['content']);

        return $data;
    }

    /**
     * A gate that inspects content sees an empty document otherwise, and refuses
     * content that is perfectly good.
     */
    public function test_it_puts_an_overflowed_field_back_inline(): void
    {
        $data = $this->blobbedRecord();

        $resolved = $this->resolve($data);

        $this->assertCount(21, $resolved['content']['items']);
        $this->assertSame('Paragraph 0 of a document that keeps going.', $resolved['content']['items'][0]['text']);
        $this->assertArrayNotHasKey('blob', $resolved['content']);
        $this->assertArrayNotHasKey('references', $resolved['content']);
    }

    public function test_it_leaves_an_inline_record_alone_and_downloads_nothing(): void
    {
        $data = $this->mapper->toRecord($this->model(1))->toArray();
        $this->blobs->stored = [];

        $resolved = $this->resolve($data);

        $this->assertSame($data['content']['items'], $resolved['content']['items']);
    }

    /** The inline value is the one the author wrote, so it wins. */
    public function test_an_inline_value_beats_a_blob(): void
    {
        $data = $this->blobbedRecord();
        $data['content']['items'] = [['type' => 'text', 'text' => 'written inline']];

        $resolved = $this->resolve($data);

        $this->assertSame('written inline', $resolved['content']['items'][0]['text']);
    }

    /** "Could not read" must not read as "empty", so the record is left overflowed. */
    public function test_a_failed_download_leaves_the_record_overflowed(): void
    {
        $data = $this->blobbedRecord();
        $this->blobs->downloadFailure = 'pds unreachable';

        $resolved = $this->resolve($data);

        $this->assertArrayNotHasKey('items', $resolved['content']);
        $this->assertArrayHasKey('blob', $resolved['content']);
    }

    /** Without a repo there is nowhere to fetch from. */
    public function test_it_does_nothing_without_a_did(): void
    {
        $data = $this->blobbedRecord();

        $resolved = $this->resolve($data, []);

        $this->assertArrayHasKey('blob', $resolved['content']);
    }
}
