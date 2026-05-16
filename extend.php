<?php

use Ekumanov\RichEmbedsDisplay\Api\Controller\DismissEmbedController;
use Ekumanov\RichEmbedsDisplay\Api\Controller\RestoreEmbedController;
use Ekumanov\RichEmbedsDisplay\Console\BackfillEmbedsCommand;
use Ekumanov\RichEmbedsDisplay\Console\SweepStuckEmbedsCommand;
use Ekumanov\RichEmbedsDisplay\Embed;
use Ekumanov\RichEmbedsDisplay\Listener\ScanPostUrls;
use Ekumanov\RichEmbedsDisplay\PostResourceFields;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Post\Post;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Model(Post::class))
        ->relationship('richEmbedsDisplay', function (Post $post) {
            return $post
                ->belongsToMany(Embed::class, 'ekumanov_rich_embed_post', 'post_id', 'embed_id')
                ->withPivot('dismissed_at')
                ->wherePivot('is_link', 1)
                ->where('http_status', 200)
                ->whereNull('error');
        }),

    (new Extend\ApiResource(Resource\PostResource::class))
        ->fields(PostResourceFields::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class, Endpoint\Create::class, Endpoint\Update::class],
            fn ($endpoint) => $endpoint->eagerLoad('richEmbedsDisplay')
        ),

    (new Extend\ApiResource(Resource\DiscussionResource::class))
        ->endpoint(
            [Endpoint\Show::class, Endpoint\Index::class],
            fn ($endpoint) => $endpoint->eagerLoadWhenIncluded([
                'posts' => ['posts.richEmbedsDisplay'],
                'firstPost' => ['firstPost.richEmbedsDisplay'],
                'lastPost' => ['lastPost.richEmbedsDisplay'],
            ])
        ),

    // ─── Fetch pipeline ────────────────────────────────────────────────
    //
    // Bind interfaces to default implementations so the container can wire
    // SafeHttpClient end-to-end. Tests instantiate the client directly with
    // fakes; production code resolves via DI.

    (new Extend\ServiceProvider())
        ->register(\Ekumanov\RichEmbedsDisplay\RichEmbedsServiceProvider::class),

    // Permission: gate URL→embed scanning on the post author's group. Default
    // grant covered by Extend\Policy/Permissions in stock Flarum — we just
    // need to expose the key so admins can toggle it. Members are granted by
    // the standard "members" group permission editor.

    // Event hooks: scan and enqueue on every new/edited post.
    (new Extend\Event())
        ->listen(Posted::class, ScanPostUrls::class)
        ->listen(Revised::class, ScanPostUrls::class),

    // Dismiss / restore the card on a (post, embed) pair. Authors can do
    // their own posts; mods/admins can do any. See controller for the
    // permission gate (single Gate::allows('edit', $post) check covers both).
    (new Extend\Routes('api'))
        ->post('/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss', 'rich-embeds.dismiss', DismissEmbedController::class)
        ->delete('/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss', 'rich-embeds.restore', RestoreEmbedController::class),

    // Default settings — admins override via the admin settings page (v2.0+).
    // Blacklist default is empty: every URL gets a fetch+card unless the
    // admin explicitly excludes a host. Dismiss/restore controls give
    // authors+mods per-card override on top of the host-level setting.
    (new Extend\Settings())
        ->default('ekumanov-rich-embeds.blacklist', ''),

    // Scheduler safety-net: every 5 min, re-dispatch any placeholder embed
    // rows whose original FetchEmbedJob seems to have been dropped (worker
    // restart, Redis flush, etc.). Also exposed as `php flarum richembeds:sweep`.
    (new Extend\Console())
        ->command(SweepStuckEmbedsCommand::class)
        ->command(BackfillEmbedsCommand::class)
        ->schedule(SweepStuckEmbedsCommand::class, function (ScheduleEvent $event) {
            $event->everyFiveMinutes()->withoutOverlapping();
        }),
];
