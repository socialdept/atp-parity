<?php

namespace SocialDept\AtpParity\Tests\Unit\PendingSync;

use Carbon\CarbonImmutable;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\PendingSync\PendingSync;
use SocialDept\AtpParity\Tests\TestCase;

class PendingSyncTest extends TestCase
{
    public function test_can_create_pending_sync(): void
    {
        $createdAt = CarbonImmutable::now();

        $pendingSync = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test123',
            modelClass: 'App\\Models\\Post',
            modelId: 42,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: $createdAt,
            attempts: 0,
        );

        $this->assertSame('01ABC123', $pendingSync->id);
        $this->assertSame('did:plc:test123', $pendingSync->did);
        $this->assertSame('App\\Models\\Post', $pendingSync->modelClass);
        $this->assertSame(42, $pendingSync->modelId);
        $this->assertSame(PendingSyncOperation::Sync, $pendingSync->operation);
        $this->assertNull($pendingSync->referenceMapperClass);
        $this->assertSame($createdAt, $pendingSync->createdAt);
        $this->assertSame(0, $pendingSync->attempts);
    }

    public function test_can_convert_to_array(): void
    {
        $createdAt = CarbonImmutable::parse('2025-01-10 12:00:00');

        $pendingSync = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test123',
            modelClass: 'App\\Models\\Post',
            modelId: 42,
            operation: PendingSyncOperation::SyncWithReference,
            referenceMapperClass: 'App\\Mappers\\PostReferenceMapper',
            createdAt: $createdAt,
            attempts: 2,
        );

        $array = $pendingSync->toArray();

        $this->assertSame('01ABC123', $array['id']);
        $this->assertSame('did:plc:test123', $array['did']);
        $this->assertSame('App\\Models\\Post', $array['model_class']);
        $this->assertSame(42, $array['model_id']);
        $this->assertSame('sync_with_reference', $array['operation']);
        $this->assertSame('App\\Mappers\\PostReferenceMapper', $array['reference_mapper_class']);
        $this->assertSame(2, $array['attempts']);
    }

    public function test_can_create_from_array(): void
    {
        $array = [
            'id' => '01XYZ789',
            'did' => 'did:plc:user456',
            'model_class' => 'App\\Models\\Comment',
            'model_id' => '123',
            'operation' => 'resync',
            'reference_mapper_class' => null,
            'created_at' => '2025-01-10T14:30:00+00:00',
            'attempts' => 1,
        ];

        $pendingSync = PendingSync::fromArray($array);

        $this->assertSame('01XYZ789', $pendingSync->id);
        $this->assertSame('did:plc:user456', $pendingSync->did);
        $this->assertSame('App\\Models\\Comment', $pendingSync->modelClass);
        $this->assertSame('123', $pendingSync->modelId);
        $this->assertSame(PendingSyncOperation::Resync, $pendingSync->operation);
        $this->assertNull($pendingSync->referenceMapperClass);
        $this->assertSame(1, $pendingSync->attempts);
    }

    public function test_with_incremented_attempts_returns_new_instance(): void
    {
        $original = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test',
            modelClass: 'App\\Models\\Post',
            modelId: 1,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: CarbonImmutable::now(),
            attempts: 0,
        );

        $incremented = $original->withIncrementedAttempts();

        $this->assertSame(0, $original->attempts);
        $this->assertSame(1, $incremented->attempts);
        $this->assertNotSame($original, $incremented);
        $this->assertSame($original->id, $incremented->id);
    }

    public function test_is_expired_returns_true_when_past_ttl(): void
    {
        $pendingSync = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test',
            modelClass: 'App\\Models\\Post',
            modelId: 1,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: CarbonImmutable::now()->subHours(2),
            attempts: 0,
        );

        $this->assertTrue($pendingSync->isExpired(3600)); // 1 hour TTL
        $this->assertFalse($pendingSync->isExpired(86400)); // 24 hour TTL
    }

    public function test_is_expired_returns_false_when_within_ttl(): void
    {
        $pendingSync = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test',
            modelClass: 'App\\Models\\Post',
            modelId: 1,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: CarbonImmutable::now()->subMinutes(30),
            attempts: 0,
        );

        $this->assertFalse($pendingSync->isExpired(3600));
    }

    public function test_has_exceeded_max_attempts(): void
    {
        $pendingSync = new PendingSync(
            id: '01ABC123',
            did: 'did:plc:test',
            modelClass: 'App\\Models\\Post',
            modelId: 1,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: CarbonImmutable::now(),
            attempts: 3,
        );

        $this->assertTrue($pendingSync->hasExceededMaxAttempts(3));
        $this->assertTrue($pendingSync->hasExceededMaxAttempts(2));
        $this->assertFalse($pendingSync->hasExceededMaxAttempts(4));
    }

    public function test_supports_all_operation_types(): void
    {
        $operations = [
            PendingSyncOperation::Sync,
            PendingSyncOperation::Resync,
            PendingSyncOperation::Unsync,
            PendingSyncOperation::SyncWithReference,
            PendingSyncOperation::ResyncWithReference,
            PendingSyncOperation::UnsyncWithReference,
        ];

        foreach ($operations as $operation) {
            $pendingSync = new PendingSync(
                id: '01ABC',
                did: 'did:plc:test',
                modelClass: 'Test',
                modelId: 1,
                operation: $operation,
                referenceMapperClass: null,
                createdAt: CarbonImmutable::now(),
            );

            $array = $pendingSync->toArray();
            $restored = PendingSync::fromArray($array);

            $this->assertSame($operation, $restored->operation);
        }
    }
}
