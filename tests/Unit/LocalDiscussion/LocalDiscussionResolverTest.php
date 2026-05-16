<?php

namespace Ekumanov\RichEmbedsDisplay\Tests\Unit\LocalDiscussion;

use Ekumanov\RichEmbedsDisplay\LocalDiscussion\LocalDiscussionResolver;
use Ekumanov\RichEmbedsDisplay\Tests\Unit\Listener\InMemorySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * URL-parsing tests only. The DB lookup path (`resolve()`) is exercised via
 * the integration smoke test on the mirror — too much Eloquent surface to
 * mock here cheaply.
 */
final class LocalDiscussionResolverTest extends TestCase
{
    #[DataProvider('selfLinkProvider')]
    public function test_self_link_detected(string $forumBase, string $url, int $expectedId): void
    {
        $r = new LocalDiscussionResolver($forumBase, new InMemorySettings());
        $this->assertSame($expectedId, $r->parseSelfLink($url));
    }

    public static function selfLinkProvider(): array
    {
        return [
            // pianoclack-style: forum mounted under /forum
            'subpath-bare-id'         => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449', 2449],
            'subpath-with-slug'       => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449-pianos-and-design', 2449],
            'subpath-with-postnum'    => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449/5', 2449],
            'subpath-slug-and-postnum' => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449-pianos-and-design/5', 2449],
            'subpath-trailing-slash'  => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449-pianos-and-design/', 2449],

            // root-mounted forum (e.g. https://example.com/d/...)
            'root-bare-id'            => ['https://example.com', 'https://example.com/d/42', 42],
            'root-with-slug'          => ['https://example.com', 'https://example.com/d/42-some-slug', 42],

            // www. on either side
            'www-on-url'              => ['https://pianoclack.com/forum', 'https://www.pianoclack.com/forum/d/2449', 2449],
            'www-on-base'             => ['https://www.pianoclack.com/forum', 'https://pianoclack.com/forum/d/2449', 2449],

            // schemes can differ — we don't care about scheme, just host+path
            'http-vs-https'           => ['https://pianoclack.com/forum', 'http://pianoclack.com/forum/d/2449', 2449],
        ];
    }

    #[DataProvider('notSelfLinkProvider')]
    public function test_non_self_link_returns_null(string $forumBase, string $url): void
    {
        $r = new LocalDiscussionResolver($forumBase, new InMemorySettings());
        $this->assertNull($r->parseSelfLink($url));
    }

    public static function notSelfLinkProvider(): array
    {
        return [
            'different-host'      => ['https://pianoclack.com/forum', 'https://example.com/forum/d/2449'],
            'different-subdomain' => ['https://pianoclack.com/forum', 'https://staging.pianoclack.com/forum/d/2449'],

            // path patterns that aren't a discussion view
            'home-page'           => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/'],
            'tag-page'            => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/t/general'],
            'user-page'           => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/u/CyberGene'],
            'admin'               => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/admin'],
            'static-page'         => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/p/1-about'],

            // forum mounted under /forum, URL outside it
            'wrong-subpath'       => ['https://pianoclack.com/forum', 'https://pianoclack.com/d/2449'],
            'root-extra-path'    => ['https://pianoclack.com/forum', 'https://pianoclack.com/blog/d/2449'],

            // non-numeric IDs / malformed
            'non-numeric-id'      => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/abc-slug'],
            'no-id'               => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d/'],
            'just-d'              => ['https://pianoclack.com/forum', 'https://pianoclack.com/forum/d'],
            'empty-url'           => ['https://pianoclack.com/forum', ''],
            'invalid-url'         => ['https://pianoclack.com/forum', 'not a url'],
        ];
    }
}
