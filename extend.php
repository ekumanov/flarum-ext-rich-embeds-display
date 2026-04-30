<?php

use Ekumanov\RichEmbedsDisplay\Embed;
use Ekumanov\RichEmbedsDisplay\PostResourceFields;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Post\Post;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Model(Post::class))
        ->relationship('richEmbedsDisplay', function (Post $post) {
            return $post
                ->belongsToMany(Embed::class, 'kilowhat_rich_embed_post', 'post_id', 'embed_id')
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
];
