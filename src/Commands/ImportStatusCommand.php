<?php

namespace SocialDept\AtpParity\Commands;

use Illuminate\Console\Command;
use SocialDept\AtpParity\Import\ImportState;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class ImportStatusCommand extends Command
{
    protected $signature = 'parity:import-status
        {did? : Show status for specific DID}
        {--pending : Show only pending/incomplete imports}
        {--failed : Show only failed imports}
        {--completed : Show only completed imports}';

    protected $description = 'Show import status';

    public function handle(): int
    {
        $did = $this->argument('did');

        if ($did) {
            return $this->showDidStatus($did);
        }

        return $this->showAllStatus();
    }

    protected function showDidStatus(string $did): int
    {
        $states = ImportState::where('did', $did)->get();

        if ($states->isEmpty()) {
            note("No import records found for {$did}");

            return self::SUCCESS;
        }

        info("Import status for {$did}");

        table(
            headers: ['Collection', 'Status', 'Synced', 'Skipped', 'Failed', 'Started', 'Completed'],
            rows: $states->map(fn (ImportState $state) => [
                $state->collection,
                $this->formatStatus($state->status),
                $state->records_synced,
                $state->records_skipped,
                $state->records_failed,
                $state->started_at?->diffForHumans() ?? '-',
                $state->completed_at?->diffForHumans() ?? '-',
            ])->toArray()
        );

        return self::SUCCESS;
    }

    protected function showAllStatus(): int
    {
        $query = ImportState::query();

        if ($this->option('pending')) {
            $query->incomplete();
        } elseif ($this->option('failed')) {
            $query->failed();
        } elseif ($this->option('completed')) {
            $query->completed();
        }

        $states = $query->orderByDesc('updated_at')->limit(100)->get();

        if ($states->isEmpty()) {
            note('No import records found');

            return self::SUCCESS;
        }

        $this->displaySummary();

        table(
            headers: ['DID', 'Collection', 'Status', 'Synced', 'Updated'],
            rows: $states->map(fn (ImportState $state) => [
                $this->truncateDid($state->did),
                $state->collection,
                $this->formatStatus($state->status),
                $state->records_synced,
                $state->updated_at->diffForHumans(),
            ])->toArray()
        );

        if ($states->count() >= 100) {
            note('Showing first 100 results. Use --pending, --failed, or --completed to filter.');
        }

        return self::SUCCESS;
    }

    protected function displaySummary(): void
    {
        $counts = ImportState::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $pending = $counts->get('pending', 0);
        $inProgress = $counts->get('in_progress', 0);
        $completed = $counts->get('completed', 0);
        $failed = $counts->get('failed', 0);

        info("Import Status Summary");
        note("Pending: {$pending} | In Progress: {$inProgress} | Completed: {$completed} | Failed: {$failed}");

        if ($failed > 0) {
            warning("Use 'php artisan parity:import --resume' to retry failed imports");
        }

        $this->newLine();
    }

    protected function formatStatus(string $status): string
    {
        return match ($status) {
            ImportState::STATUS_PENDING => 'pending',
            ImportState::STATUS_IN_PROGRESS => 'running',
            ImportState::STATUS_COMPLETED => 'done',
            ImportState::STATUS_FAILED => 'FAILED',
            default => $status,
        };
    }

    protected function truncateDid(string $did): string
    {
        if (strlen($did) <= 30) {
            return $did;
        }

        return substr($did, 0, 15).'...'.substr($did, -12);
    }
}
