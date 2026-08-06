<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpParity\Contracts\DeferredReferenceStore;
use SocialDept\AtpParity\Events\DeferredReferenceExpired;

/**
 * Clears reference records whose target never arrived.
 *
 * Some orphans are permanent: a reference pointing at a record we will never
 * hold has nothing to wait for. They are indistinguishable at park time from a
 * record that is merely late, which is why the TTL is generous and the count is
 * surfaced — a climbing number is the signal, not the timeout.
 */
class PruneDeferredReferencesCommand extends Command
{
    protected $signature = 'parity:prune-deferred
                            {--days= : Override the configured TTL}
                            {--execute : Actually delete the expired references}';

    protected $description = 'Prune deferred references whose target never arrived';

    public function handle(DeferredReferenceStore $store): int
    {
        $days = (int) ($this->option('days') ?? config('atp-parity.deferred_references.ttl_days', 7));
        $before = now()->subDays($days);

        $this->components->twoColumnDetail('Parked references', (string) $store->count());
        $this->components->twoColumnDetail('Cutoff', $before->toDateTimeString()." ({$days} days)");

        if (! $this->option('execute')) {
            $this->newLine();
            $this->components->warn('Dry run. Re-run with --execute to prune.');

            return self::SUCCESS;
        }

        $expired = $store->prune($before);

        foreach ($expired as $reference) {
            // Emitted per reference so an expiry is observable rather than a
            // number silently going down.
            event(new DeferredReferenceExpired(
                $reference->referenceUri,
                $reference->targetUri,
                $reference->collection,
            ));
        }

        $count = count($expired);

        $this->newLine();
        $this->components->info("Pruned {$count} deferred ".str('reference')->plural($count).'.');

        return self::SUCCESS;
    }
}
