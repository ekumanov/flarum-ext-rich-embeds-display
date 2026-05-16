<?php

namespace Ekumanov\RichEmbedsDisplay\Job;

use Carbon\Carbon;
use Ekumanov\RichEmbedsDisplay\Embed;
use Ekumanov\RichEmbedsDisplay\Http\SafeHttpClient;
use Ekumanov\RichEmbedsDisplay\Parser\HtmlFallbackParser;
use Ekumanov\RichEmbedsDisplay\Parser\OpenGraphParser;
use Ekumanov\RichEmbedsDisplay\Settings\SettingsRepository;
use Flarum\Queue\AbstractJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Background worker — fetch one URL, parse OG/fallback metadata, persist.
 *
 * Idempotent: if the embed row was already fetched within the TTL window, the
 * job is a no-op (handles retry storms and duplicate scheduler dispatches).
 *
 * Single-attempt: $tries = 1 disables Laravel's default retry-on-throw. A
 * fetch that genuinely fails (timeout, 4xx, etc.) is recorded as a failed row
 * — we don't want a single bad URL to hammer the queue with retries. A real
 * transport panic (DB unreachable, etc.) just dies silently and the scheduler
 * sweep picks it up later.
 *
 * Bounded runtime: $timeout = 30 covers up to 10 s of fetch + parse + persist
 * with slack. Worker kills it if it overruns.
 */
class FetchEmbedJob extends AbstractJob
{
    /** @var int Disable retry-on-throw — see class docblock. */
    public int $tries = 1;

    /** @var int Per-job wall clock cap (sec). */
    public int $timeout = 30;

    public function __construct(public readonly int $embedId) {}

    public function handle(
        SafeHttpClient $client,
        OpenGraphParser $ogParser,
        HtmlFallbackParser $fbParser,
        SettingsRepository $settings,
        LoggerInterface $log,
    ): void {
        $embed = Embed::find($this->embedId);
        if ($embed === null) {
            // Row was deleted between enqueue and run. Nothing to do.
            return;
        }

        if ($embed->retrieved_at !== null) {
            $age = Carbon::parse($embed->retrieved_at)->diffInSeconds(Carbon::now());
            if ($age < $settings->ttlSeconds()) {
                return; // already fresh
            }
        }

        try {
            $result = $client->get($embed->url);
        } catch (Throwable $e) {
            // Unexpected internal failure (not an HTTP error — SafeHttpClient
            // returns those as ['ok' => false]). Log and mark errored so the
            // row doesn't sit pending forever.
            $log->warning('rich-embeds fetch threw', [
                'embed_id' => $embed->id, 'url' => $embed->url, 'err' => $e->getMessage(),
            ]);
            $embed->retrieved_at = Carbon::now();
            $embed->error = substr('exception: '.$e->getMessage(), 0, 255);
            $embed->save();
            return;
        }

        $embed->retrieved_at = Carbon::now();

        if (! $result['ok']) {
            $embed->http_status = 0;
            $embed->error = substr($result['reason'].': '.$result['detail'], 0, 255);
            $embed->save();
            return;
        }

        $embed->http_status = $result['status'];
        $embed->final_url = $result['finalUrl'];
        $embed->error = null;

        // Only parse HTML bodies — anything else (PDF, JSON, image MIME, etc.)
        // we record the status but leave metadata empty. The display layer
        // skips rows without a title, so they simply don't produce a card.
        $contentType = strtolower($result['contentType']);
        if (str_contains($contentType, 'html') || str_contains($contentType, 'xml')) {
            $og = $ogParser->parse($result['body']);
            $fb = $fbParser->parse($result['body']);

            if ($og !== null) {
                $embed->opengraph = $og;
            }
            if ($fb['fallback'] !== null) {
                $embed->fallback = $fb['fallback'];
            }
            if ($fb['icons'] !== []) {
                $embed->icons = $fb['icons'];
            }
        }

        $embed->save();
    }
}
