<?php

namespace Ekumanov\RichEmbedsDisplay\Console;

use Carbon\Carbon;
use Ekumanov\RichEmbedsDisplay\Job\FetchEmbedJob;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;

/**
 * Re-dispatches FetchEmbedJob for embed rows that have a placeholder but no
 * retrieved_at — i.e. the original job got lost (queue crash, Redis flush,
 * worker died mid-fetch).
 *
 * Wired into Flarum's scheduler at 5-minute intervals from extend.php; can
 * also be triggered manually via `php flarum richembeds:sweep`.
 *
 * Looks back 6h max — older rows are someone else's problem (e.g. a manual
 * import that never had a job dispatched in the first place). Anyone wanting
 * to force-refetch ancient rows can re-post the URL or zero out `retrieved_at`
 * on the row in the DB and run sweep again.
 */
class SweepStuckEmbedsCommand extends Command
{
    protected $signature = 'richembeds:sweep
                            {--limit=200 : Maximum rows to re-enqueue in one run}
                            {--age=300  : Minimum row age in seconds before a missed row is swept (default 5 min)}';

    protected $description = 'Re-dispatch FetchEmbedJob for placeholder embed rows whose worker job appears to have been dropped.';

    public function handle(ConnectionInterface $db, Queue $queue): int
    {
        $limit = (int) $this->option('limit');
        $ageSec = (int) $this->option('age');

        $cutoff = Carbon::now()->subSeconds($ageSec);
        $floor = Carbon::now()->subHours(6);

        $rows = $db->table('kilowhat_rich_embeds')
            ->select('id')
            ->whereNull('retrieved_at')
            ->where('created_at', '<', $cutoff)
            ->where('created_at', '>', $floor)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No stuck embeds to sweep.');
            return 0;
        }

        foreach ($rows as $row) {
            $queue->push(new FetchEmbedJob((int) $row->id));
        }

        $this->info('Re-dispatched '.$rows->count().' stuck embed(s).');
        return 0;
    }
}
