<?php

namespace SocialDept\AtpParity\Tests\Unit\Blob;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use SocialDept\AtpParity\Contracts\BlobResolver;
use SocialDept\AtpParity\Tests\Fixtures\BlobMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * Blobs resolve before the record is built, and building it performs no I/O.
 *
 * While an upload happened inside the build, deciding whether a write was necessary
 * meant possibly uploading first, so the check cost more than the write it was trying
 * to avoid. Splitting the phases is what makes "what would we write" cheap.
 */
class BlobResolutionTest extends TestCase
{
    private function resolver(): BlobResolver
    {
        return new class () implements BlobResolver {
            public int $calls = 0;

            public function resolve(Model $model, string $field): ?array
            {
                $this->calls++;

                // A resolver is allowed to reach the network. Construction is not.
                Http::get('https://example.test/upload');

                return ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiStub'], 'mimeType' => 'image/png', 'size' => 1];
            }
        };
    }

    public function test_a_declared_blob_lands_in_the_record(): void
    {
        Http::fake();
        config(['atp-parity.blobs.resolver' => $this->resolver()]);

        $record = (new BlobMapper())->toRecord(new TestModel(['content' => 'hello']))->toArray();

        $this->assertSame('bafkreiStub', $record['payload']['ref']['$link']);
    }

    /**
     * The point of the split. Construction alone must not touch the network, so the
     * record it produces carries no blob and costs nothing to produce.
     */
    public function test_building_the_record_without_resolution_performs_no_io(): void
    {
        Http::fake();

        $data = (new BlobMapper())->fieldMap()->toRecordData(new TestModel(['content' => 'hello', 'payload' => 'ignored']));

        $this->assertArrayNotHasKey('payload', $data, 'Construction must leave blobs to the resolution phase.');
        Http::assertNothingSent();
    }

    public function test_resolution_runs_once_per_blob_field(): void
    {
        Http::fake();
        $resolver = $this->resolver();
        config(['atp-parity.blobs.resolver' => $resolver]);

        (new BlobMapper())->toRecord(new TestModel(['content' => 'hello']));

        $this->assertSame(1, $resolver->calls);
    }

    /**
     * A mapper may declare a blob before the app has wired resolution up, and that
     * should leave the field absent rather than fail the whole write.
     */
    public function test_an_unconfigured_resolver_omits_the_blob_rather_than_failing(): void
    {
        Http::fake();
        config(['atp-parity.blobs.resolver' => null]);

        $record = (new BlobMapper())->toRecord(new TestModel(['content' => 'hello']))->toArray();

        $this->assertSame('hello', $record['text']);
        $this->assertArrayNotHasKey('payload', $record);
        Http::assertNothingSent();
    }

    public function test_a_mapper_with_no_blob_fields_never_asks_for_a_resolver(): void
    {
        Http::fake();
        $resolver = $this->resolver();
        config(['atp-parity.blobs.resolver' => $resolver]);

        (new \SocialDept\AtpParity\Tests\Fixtures\DeclarativeMapper())
            ->toRecord(new TestModel(['content' => 'hello']));

        $this->assertSame(0, $resolver->calls);
    }
}
