<?php

namespace Ekumanov\RichEmbedsDisplay\Api\Controller;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss
 *
 * Marks the (post, embed) pivot row as dismissed so the front-end stops
 * rendering its card. Idempotent — calling on an already-dismissed row is
 * a no-op (still returns 204). Permission: any actor who can edit the
 * post — i.e. the post author or anyone with the discussion.editPost
 * permission (mods, admins).
 */
class DismissEmbedController implements RequestHandlerInterface
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getAttribute('routeParameters', []);
        $postId = (int) ($params['postId'] ?? 0);
        $embedId = (int) ($params['embedId'] ?? 0);

        $post = $this->posts->findOrFail($postId, $actor);

        // Single permission check covers (a) post author (via discussion.editOwnPost
        // policy in core) and (b) mods/admins (via discussion.editPost).
        if (! $actor->can('edit', $post)) {
            throw new PermissionDeniedException();
        }

        $this->db->table('kilowhat_rich_embed_post')
            ->where('post_id', $postId)
            ->where('embed_id', $embedId)
            ->update(['dismissed_at' => Carbon::now()]);

        return new EmptyResponse(204);
    }
}
