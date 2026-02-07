<?php

namespace SocialDept\AtpParity\Tests\Unit\PendingSync;

use Carbon\CarbonImmutable;
use SocialDept\AtpParity\Enums\PendingSyncOperation;
use SocialDept\AtpParity\PendingSync\DatabasePendingSyncStore;
use SocialDept\AtpParity\PendingSync\PendingSync;
use SocialDept\AtpParity\PendingSync\PendingSyncState;
use SocialDept\AtpParity\Tests\TestCase;

class DatabasePendingSyncStoreTest extends TestCase
{
    private DatabasePendingSyncStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DatabasePendingSyncStore;
    }

    public function test_can_store_and_retrieve_pending_sync(): void
    {
        $pendingSync = $this->createPendingSync('id1', 'did:plc:test');

        $this->store->store($pendingSync);

        $found = $this->store->find('id1');

        $this->assertNotNull($found);
        $this->assertSame('id1', $found->id);
        $this->assertSame('did:plc:test', $found->did);
    }

    public function test_find_returns_null_for_nonexistent_id(): void
    {
        $this->assertNull($this->store->find('nonexistent'));
    }

    public function test_for_did_returns_all_pending_syncs_for_did(): void
    {
        $sync1 = $this->createPendingSync('id1', 'did:plc:user1');
        $sync2 = $this->createPendingSync('id2', 'did:plc:user1');
        $sync3 = $this->createPendingSync('id3', 'did:plc:user2');

        $this->store->store($sync1);
        $this->store->store($sync2);
        $this->store->store($sync3);

        $user1Syncs = $this->store->forDid('did:plc:user1');
        $user2Syncs = $this->store->forDid('did:plc:user2');

        $this->assertCount(2, $user1Syncs);
        $this->assertCount(1, $user2Syncs);
    }

    public function test_for_did_returns_empty_array_when_no_syncs(): void
    {
        $syncs = $this->store->forDid('did:plc:nobody');

        $this->assertIsArray($syncs);
        $this->assertEmpty($syncs);
    }

    public function test_update_modifies_pending_sync(): void
    {
        $pendingSync = $this->createPendingSync('id1', 'did:plc:test');
        $this->store->store($pendingSync);

        $updated = $pendingSync->withIncrementedAttempts();
        $this->store->update($updated);

        $found = $this->store->find('id1');

        $this->assertSame(1, $found->attempts);
    }

    public function test_remove_deletes_pending_sync(): void
    {
        $pendingSync = $this->createPendingSync('id1', 'did:plc:test');
        $this->store->store($pendingSync);

        $this->assertNotNull($this->store->find('id1'));

        $this->store->remove('id1');

        $this->assertNull($this->store->find('id1'));
    }

    public function test_remove_for_did_removes_all_syncs_for_did(): void
    {
        $sync1 = $this->createPendingSync('id1', 'did:plc:user1');
        $sync2 = $this->createPendingSync('id2', 'did:plc:user1');
        $sync3 = $this->createPendingSync('id3', 'did:plc:user2');

        $this->store->store($sync1);
        $this->store->store($sync2);
        $this->store->store($sync3);

        $removed = $this->store->removeForDid('did:plc:user1');

        $this->assertSame(2, $removed);
        $this->assertEmpty($this->store->forDid('did:plc:user1'));
        $this->assertCount(1, $this->store->forDid('did:plc:user2'));
    }

    public function test_remove_for_model_removes_syncs_for_specific_model(): void
    {
        $sync1 = $this->createPendingSync('id1', 'did:plc:user1', 'App\\Post', 1);
        $sync2 = $this->createPendingSync('id2', 'did:plc:user1', 'App\\Post', 2);
        $sync3 = $this->createPendingSync('id3', 'did:plc:user1', 'App\\Post', 1);

        $this->store->store($sync1);
        $this->store->store($sync2);
        $this->store->store($sync3);

        $removed = $this->store->removeForModel('App\\Post', 1);

        $this->assertSame(2, $removed);
        $this->assertNull($this->store->find('id1'));
        $this->assertNotNull($this->store->find('id2'));
        $this->assertNull($this->store->find('id3'));
    }

    public function test_remove_expired_removes_old_entries(): void
    {
        // Set TTL to 1 hour before creating entries
        $this->app['config']->set('atp-parity.pending_syncs.ttl', 3600);

        // Create an old entry and manually update timestamps
        $old = PendingSyncState::create([
            'pending_id' => 'old-id',
            'did' => 'did:plc:test',
            'model_class' => 'App\\Post',
            'model_id' => '1',
            'operation' => 'sync',
            'attempts' => 0,
        ]);

        // Force update created_at using query builder to bypass Eloquent's timestamp handling
        PendingSyncState::where('id', $old->id)
            ->update(['created_at' => now()->subHours(2)]);

        // Create a fresh entry
        $fresh = $this->createPendingSync('fresh-id', 'did:plc:test');
        $this->store->store($fresh);

        $removed = $this->store->removeExpired();

        $this->assertSame(1, $removed);
        $this->assertNull($this->store->find('old-id'));
        $this->assertNotNull($this->store->find('fresh-id'));
    }

    public function test_count_for_did_returns_correct_count(): void
    {
        $sync1 = $this->createPendingSync('id1', 'did:plc:user1');
        $sync2 = $this->createPendingSync('id2', 'did:plc:user1');

        $this->store->store($sync1);
        $this->store->store($sync2);

        $this->assertSame(2, $this->store->countForDid('did:plc:user1'));
        $this->assertSame(0, $this->store->countForDid('did:plc:user2'));
    }

    public function test_has_for_did_returns_boolean(): void
    {
        $pendingSync = $this->createPendingSync('id1', 'did:plc:user1');
        $this->store->store($pendingSync);

        $this->assertTrue($this->store->hasForDid('did:plc:user1'));
        $this->assertFalse($this->store->hasForDid('did:plc:user2'));
    }

    public function test_stores_reference_mapper_class(): void
    {
        $pendingSync = new PendingSync(
            id: 'id1',
            did: 'did:plc:test',
            modelClass: 'App\\Post',
            modelId: 1,
            operation: PendingSyncOperation::SyncWithReference,
            referenceMapperClass: 'App\\Mappers\\PostReferenceMapper',
            createdAt: CarbonImmutable::now(),
            attempts: 0,
        );

        $this->store->store($pendingSync);

        $found = $this->store->find('id1');

        $this->assertSame('App\\Mappers\\PostReferenceMapper', $found->referenceMapperClass);
        $this->assertSame(PendingSyncOperation::SyncWithReference, $found->operation);
    }

    public function test_preserves_operation_type(): void
    {
        foreach (PendingSyncOperation::cases() as $operation) {
            $pendingSync = new PendingSync(
                id: "id-{$operation->value}",
                did: 'did:plc:test',
                modelClass: 'App\\Post',
                modelId: 1,
                operation: $operation,
                referenceMapperClass: null,
                createdAt: CarbonImmutable::now(),
                attempts: 0,
            );

            $this->store->store($pendingSync);
            $found = $this->store->find("id-{$operation->value}");

            $this->assertSame($operation, $found->operation);
        }
    }

    private function createPendingSync(
        string $id,
        string $did,
        string $modelClass = 'App\\Models\\TestModel',
        int|string $modelId = 1
    ): PendingSync {
        return new PendingSync(
            id: $id,
            did: $did,
            modelClass: $modelClass,
            modelId: $modelId,
            operation: PendingSyncOperation::Sync,
            referenceMapperClass: null,
            createdAt: CarbonImmutable::now(),
            attempts: 0,
        );
    }
}
