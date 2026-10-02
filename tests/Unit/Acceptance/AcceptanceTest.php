<?php

namespace SocialDept\AtpParity\Tests\Unit\Acceptance;

use SocialDept\AtpParity\Acceptance\Acceptance;
use SocialDept\AtpParity\Support\SchemaMapper;
use SocialDept\AtpParity\Tests\Fixtures\TestModel;
use SocialDept\AtpParity\Tests\Fixtures\TestRecord;
use SocialDept\AtpParity\Tests\TestCase;

/**
 * A mapper that has not said what it accepts accepts nothing.
 *
 * Every mapper is an ingest boundary, so the failure mode of forgetting a guard is
 * that any repo on the network can write rows in our database. That is silent and
 * total, which is the wrong thing to leave to remembering a trait.
 *
 * The inverse failure, refusing records we wanted, is loud: rows stop appearing and
 * someone notices. So the default goes that way.
 */
class AcceptanceTest extends TestCase
{
    private function mapper(?Acceptance $accepts): SchemaMapper
    {
        return new SchemaMapper(
            schemaClass: TestRecord::class,
            modelClass: TestModel::class,
            toAttributes: fn (TestRecord $r) => ['content' => $r->text],
            toRecordData: fn (TestModel $m) => ['text' => $m->content],
            accepts: $accepts,
        );
    }

    public function test_a_mapper_that_declares_nothing_accepts_nothing(): void
    {
        $this->assertFalse(
            $this->mapper(null)->shouldImport(new TestRecord(text: 'x'), ['did' => 'did:plc:anyone']),
        );
    }

    public function test_accepting_anything_has_to_be_written_down(): void
    {
        $this->assertTrue(
            $this->mapper(Acceptance::anything())->shouldImport(new TestRecord(text: 'x'), []),
        );
    }

    public function test_a_predicate_sees_the_record_and_the_meta(): void
    {
        $policy = Acceptance::when(
            fn (TestRecord $record, array $meta) => $record->text === 'wanted' && ($meta['did'] ?? null) === 'did:plc:ours',
        );

        $mapper = $this->mapper($policy);

        $this->assertTrue($mapper->shouldImport(new TestRecord(text: 'wanted'), ['did' => 'did:plc:ours']));
        $this->assertFalse($mapper->shouldImport(new TestRecord(text: 'wanted'), ['did' => 'did:plc:theirs']));
        $this->assertFalse($mapper->shouldImport(new TestRecord(text: 'other'), ['did' => 'did:plc:ours']));
    }

    public function test_policies_compose(): void
    {
        $yes = Acceptance::anything();
        $no = Acceptance::none();
        $record = new TestRecord(text: 'x');

        $this->assertTrue($this->mapper($yes->or($no))->shouldImport($record, []));
        $this->assertFalse($this->mapper($yes->and($no))->shouldImport($record, []));
    }

    /**
     * Whether a repo is ours is the host app's knowledge, so the policy delegates. An
     * unconfigured lookup must not read as "yes".
     */
    public function test_a_policy_accepts_nothing_without_its_configured_lookup(): void
    {
        config(['atp-parity.acceptance.is_connected_actor' => null, 'atp-parity.acceptance.is_known_actor' => null]);

        $record = new TestRecord(text: 'x');
        $meta = ['did' => 'did:plc:ours'];

        $this->assertFalse($this->mapper(Acceptance::connectedActors())->shouldImport($record, $meta));
        $this->assertFalse($this->mapper(Acceptance::knownActors())->shouldImport($record, $meta));
    }

    public function test_each_policy_consults_its_own_lookup(): void
    {
        config([
            'atp-parity.acceptance.is_connected_actor' => fn (string $did) => $did === 'did:plc:tokens',
            'atp-parity.acceptance.is_known_actor' => fn (string $did) => in_array($did, ['did:plc:tokens', 'did:plc:known'], true),
        ]);

        $record = new TestRecord(text: 'x');
        $connected = $this->mapper(Acceptance::connectedActors());
        $known = $this->mapper(Acceptance::knownActors());

        $this->assertTrue($connected->shouldImport($record, ['did' => 'did:plc:tokens']));
        $this->assertTrue($known->shouldImport($record, ['did' => 'did:plc:tokens']));

        $this->assertTrue($known->shouldImport($record, ['did' => 'did:plc:known']));
        $this->assertFalse(
            $connected->shouldImport($record, ['did' => 'did:plc:known']),
            'An actor we know but hold no tokens for has not connected their account.',
        );
    }

    /**
     * The reason these are two keys. An actor identified by a signed JWT through an
     * XRPC proxy is one we know and cannot write for, so borrowing the write lookup
     * would turn "we can write here" into "we have heard of them".
     */
    public function test_neither_policy_falls_back_to_the_other_lookup(): void
    {
        $record = new TestRecord(text: 'x');
        $meta = ['did' => 'did:plc:ours'];

        config(['atp-parity.acceptance.is_connected_actor' => fn () => true, 'atp-parity.acceptance.is_known_actor' => null]);
        $this->assertFalse($this->mapper(Acceptance::knownActors())->shouldImport($record, $meta));

        config(['atp-parity.acceptance.is_connected_actor' => null, 'atp-parity.acceptance.is_known_actor' => fn () => true]);
        $this->assertFalse($this->mapper(Acceptance::connectedActors())->shouldImport($record, $meta));
    }

    public function test_a_record_with_no_repo_is_refused_by_both(): void
    {
        config(['atp-parity.acceptance.is_connected_actor' => fn () => true, 'atp-parity.acceptance.is_known_actor' => fn () => true]);

        $record = new TestRecord(text: 'x');

        $this->assertFalse($this->mapper(Acceptance::connectedActors())->shouldImport($record, []));
        $this->assertFalse($this->mapper(Acceptance::knownActors())->shouldImport($record, []));
    }

    /**
     * A mapper that overrides shouldImport() replaces the default entirely, which is
     * what let default-deny arrive without touching any existing mapper.
     */
    public function test_an_overriding_mapper_keeps_its_own_behaviour(): void
    {
        $mapper = new class () extends \SocialDept\AtpParity\Tests\Fixtures\TestMapper {
            public function accepts(): ?Acceptance
            {
                return null;
            }

            public function shouldImport(\SocialDept\AtpSchema\Data\Data $record, array $meta = []): bool
            {
                return true;
            }
        };

        $this->assertTrue($mapper->shouldImport(new TestRecord(text: 'x'), []));
    }
}
