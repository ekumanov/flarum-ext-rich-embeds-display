<?php

namespace Ekumanov\RichEmbedsDisplay;

use Flarum\Api\Schema;
use Flarum\Post\Post;
use Illuminate\Support\Arr;

class PostResourceFields
{
    private const YOUTUBE_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    ];

    public function __invoke(): array
    {
        return [
            Schema\Arr::make('richEmbedsDisplay')
                ->get(fn (Post $post) => $this->buildPreviews($post)),
        ];
    }

    private function buildPreviews(Post $post): array
    {
        if (! $post->relationLoaded('richEmbedsDisplay')) {
            return [];
        }

        $previews = [];

        foreach ($post->getRelation('richEmbedsDisplay') as $embed) {
            if ($preview = $this->preview($embed)) {
                $previews[] = $preview;
            }
        }

        return $previews;
    }

    private function preview(Embed $embed): ?array
    {
        // Image-typed URLs already render inline as <img> via Flarum's formatter.
        if ($embed->mime && str_starts_with($embed->mime, 'image/')) {
            return null;
        }

        $clickUrl = $embed->final_url ?: $embed->url;
        $host = strtolower((string) parse_url($clickUrl, PHP_URL_HOST));

        // Flarum 2.0 auto-embeds YouTube, so a card here would be redundant.
        if (in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        $og = $embed->opengraph ?: [];
        $fallback = $embed->fallback ?: [];

        $title = Arr::get($og, 'title') ?: Arr::get($fallback, 'title');
        if (! $title) {
            return null;
        }

        $domain = preg_replace('~^www\.~', '', $host);
        $siteName = Arr::get($og, 'site_name') ?: $domain;
        $description = Arr::get($og, 'description') ?: Arr::get($fallback, 'description');

        $image = $this->firstImage($og, $fallback);

        return [
            // url is what we match against post-body <a href>; finalUrl is the click target.
            'url' => $embed->url,
            'finalUrl' => $clickUrl,
            'title' => (string) $title,
            'description' => $description ? (string) $description : null,
            'image' => $image,
            'siteName' => (string) $siteName,
            'domain' => $domain,
        ];
    }

    private function firstImage(array $og, array $fallback): ?string
    {
        foreach (Arr::get($og, 'images') ?: [] as $image) {
            $src = Arr::get($image, 'secure_url') ?: Arr::get($image, 'url');
            if ($src) {
                return (string) $src;
            }
        }

        foreach (Arr::get($fallback, 'images') ?: [] as $image) {
            $src = Arr::get($image, 'src') ?: Arr::get($image, 'url');
            if ($src) {
                return (string) $src;
            }
        }

        return null;
    }
}
