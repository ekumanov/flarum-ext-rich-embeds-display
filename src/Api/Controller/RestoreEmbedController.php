<?php

namespace Ekumanov\RichEmbedsDisplay\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\PostRepository;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * DELETE /api/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss
 *
 * Inverse of DismissEmbedController — clears dismissed_at so the card
 * re-appears. Same permission gate.
 */
class RestoreEmbedController implements RequestHandlerInterface
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

        if (! $actor->can('edit', $post)) {
            throw new PermissionDeniedException();
        }

        $this->db->table('kilowhat_rich_embed_post')
            ->where('post_id', $postId)
            ->where('embed_id', $embedId)
            ->update(['dismissed_at' => null]);

        return new EmptyResponse(204);
    }
}
