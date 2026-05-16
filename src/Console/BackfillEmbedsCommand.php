<?php

namespace Ekumanov\RichEmbedsDisplay\Console;

use Carbon\Carbon;
use Ekumanov\RichEmbedsDisplay\Embed;
use Ekumanov\RichEmbedsDisplay\Job\FetchEmbedJob;
use Ekumanov\RichEmbedsDisplay\Listener\UrlExtractor;
use Flarum\Post\CommentPost;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * One-shot backfill: replay URL-scanning over historical posts and enqueue
 * fetches for any URLs not already in the cache.
 *
 * Two scenarios this addresses:
 *   1. The cutover-gap. Pianoclack moved to Flarum 2.0 on 2026-04-26. Posts
 *      created between then and the day this extension's fetcher shipped do
 *      not have embed rows for their URLs. Backfill fills them in.
 *   2. Manual refresh of stale OG data. Pass `--force-refresh` to drop
 *      retrieved_at on matched embed rows, forcing the worker to re-fetch.
 *      Use sparingly: many sources rate-limit aggressive re-fetching.
 *
 * Safety:
 *   - Hard cap (default 200 posts/run) so a single invocation can't fill the
 *     queue. Run multiple times if you have a big backlog.
 *   - Posts processed oldest-first within the window so the user-visible
 *     order is "old discussions get cards first, recent already had them".
 *   - Per-host rate-limit and SafeHttpClient defenses still apply in the
 *     worker — backfill doesn't bypass any safety layer.
 *   - Does NOT rate-limit per-user (this is operator-initiated, not
 *     attacker-driven).
 *
 * Typical use:
 *   php flarum richembeds:backfill --since=2026-04-26 --limit=200
 *   (re-run with new --offset as needed, or schedule + walk away)
 */
class BackfillEmbedsCommand extends Command
{
    protected $signature = 'richembeds:backfill
                            {--since=  : Only scan posts created on or after this date (Y-m-d). Default: 30 days ago.}
                            {--until=  : Only scan posts created BEFORE this date (Y-m-d). Default: now.}
                            {--limit=200 : Maximum number of posts to scan in one run.}
                            {--offset=0 : Skip this many posts (paginate by re-running with offset += limit).}
                            {--force-refresh : Re-fetch URLs whose embeds already exist (resets retrieved_at).}
                            {--dry-run : Print what WOULD be scanned/enqueued without writing anything.}';

    protected $description = 'Replay URL scanning over historical posts to backfill embed rows that pre-date the fetcher.';

    public function handle(
        ConnectionInterface $db,
        UrlExtractor $extractor,
        Queue $queue,
    ): int {
        $since = $this->option('since')
            ? Carbon::parse((string) $this->option('since'))->startOfDay()
            : Carbon::now()->subDays(30);

        $until = $this->option('until')
            ? Carbon::parse((string) $this->option('until'))->endOfDay()
            : Carbon::now();

        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $force = (bool) $this->option('force-refresh');
        $dry = (bool) $this->option('dry-run');

        $this->info("Backfill window: {$since->toDateString()} → {$until->toDateString()}");
        $this->info("Limit={$limit} offset={$offset} force-refresh=".($force ? 'YES' : 'no').' dry-run='.($dry ? 'YES' : 'no'));

        $rows = $db->table('posts')
            ->select('id')
            ->where('type', 'comment')
            ->where('is_private', 0)
            ->where('is_approved', 1)
            ->whereNull('hidden_at')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->orderBy('created_at', 'asc')
            ->offset($offset)
            ->limit($limit)
            ->pluck('id');

        if ($rows->isEmpty()) {
            $this->info('No posts in window.');
            return 0;
        }

        $this->info('Scanning '.$rows->count().' post(s)...');

        $stats = ['scanned' => 0, 'urls' => 0, 'new_embeds' => 0, 'enqueued' => 0, 'skipped_cached' => 0, 'errors' => 0];

        foreach ($rows as $postId) {
            $stats['scanned']++;
            try {
                $post = CommentPost::find($postId);
                if ($post === null) {
                    continue;
                }
                $html = $post->formatContent();
                $urls = $extractor->extract($html);
                $stats['urls'] += count($urls);

                foreach ($urls as $url) {
                    $hash = sha1($url, true);
                    $embed = Embed::where('url_hash', $hash)->first();
                    $isNew = false;

                    if ($embed === null) {
                        if ($dry) {
                            $stats['new_embeds']++;
                            $stats['enqueued']++;
                            continue;
                        }
                        $embed = new Embed();
                        $embed->url = $url;
                        $embed->url_hash = $hash;
                        $embed->created_at = Carbon::now();
                        $embed->save();
                        $isNew = true;
                        $stats['new_embeds']++;
                    } elseif (! $force && $embed->retrieved_at !== null) {
                        $stats['skipped_cached']++;
                    }

                    if (! $dry) {
                        // Idempotent pivot link (post → embed).
                        $db->table('ekumanov_rich_embed_post')->insertOrIgnore([
                            'embed_id' => $embed->id,
                            'post_id'  => $postId,
                            'is_link'  => 1,
                        ]);

                        $needsFetch = $isNew
                            || $embed->retrieved_at === null
                            || ($force && ! $isNew);

                        if ($force && ! $isNew) {
                            $embed->retrieved_at = null;
                            $embed->save();
                        }

                        if ($needsFetch) {
                            $queue->push(new FetchEmbedJob($embed->id));
                            if (! $isNew) {
                                $stats['enqueued']++;
                            } else {
                                $stats['enqueued']++;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->warn("post {$postId}: {$e->getMessage()}");
            }
        }

        $this->info('Done. '.json_encode($stats));
        if ($dry) {
            $this->warn('(dry-run — nothing was written or enqueued)');
        }

        return 0;
    }
}
