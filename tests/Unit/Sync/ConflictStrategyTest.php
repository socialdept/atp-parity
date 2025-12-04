<?php

namespace SocialDept\AtpParity\Tests\Unit\Sync;

use SocialDept\AtpParity\Sync\ConflictStrategy;
use SocialDept\AtpParity\Tests\TestCase;

class ConflictStrategyTest extends TestCase
{
    public function test_remote_wins_has_correct_value(): void
    {
        $this->assertSame('remote', ConflictStrategy::RemoteWins->value);
    }

    public function test_local_wins_has_correct_value(): void
    {
        $this->assertSame('local', ConflictStrategy::LocalWins->value);
    }

    public function test_newest_wins_has_correct_value(): void
    {
        $this->assertSame('newest', ConflictStrategy::NewestWins->value);
    }

    public function test_manual_has_correct_value(): void
    {
        $this->assertSame('manual', ConflictStrategy::Manual->value);
    }

    public function test_from_config_returns_remote_wins_by_default(): void
    {
        config()->set('parity.conflicts.strategy', 'remote');

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::RemoteWins, $strategy);
    }

    public function test_from_config_returns_local_wins(): void
    {
        config()->set('parity.conflicts.strategy', 'local');

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::LocalWins, $strategy);
    }

    public function test_from_config_returns_newest_wins(): void
    {
        config()->set('parity.conflicts.strategy', 'newest');

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::NewestWins, $strategy);
    }

    public function test_from_config_returns_manual(): void
    {
        config()->set('parity.conflicts.strategy', 'manual');

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::Manual, $strategy);
    }

    public function test_from_config_defaults_to_remote_wins_for_invalid_value(): void
    {
        config()->set('parity.conflicts.strategy', 'invalid');

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::RemoteWins, $strategy);
    }

    public function test_from_config_defaults_to_remote_wins_when_not_set(): void
    {
        config()->set('parity.conflicts.strategy', null);

        $strategy = ConflictStrategy::fromConfig();

        $this->assertSame(ConflictStrategy::RemoteWins, $strategy);
    }
}
