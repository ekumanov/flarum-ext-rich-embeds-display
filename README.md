# Rich Embeds (Display Only) for Flarum 2.0

Renders preview cards for legacy `kilowhat/flarum-ext-rich-embeds` data on
Flarum 2.0. Read-only.

## Why this exists

Forums that ran the original
[kilowhat/rich-embeds](https://floxum.com/extension/kilowhat/rich-embeds)
extension on Flarum 1.x have populated `kilowhat_rich_embeds` and
`kilowhat_rich_embed_post` tables full of OpenGraph metadata. After upgrading
to Flarum 2.0 that data sits unused until the official 2.0 port of the paid
extension ships.

This package is a temporary stopgap that renders preview cards from those
existing rows. It is **not** a replacement for the real extension — it does
not scrape URLs, fetch metadata, refresh embeds, proxy images, or expose admin
controls. For any of that, buy
[kilowhat/rich-embeds](https://floxum.com/extension/kilowhat/rich-embeds) once
the 2.0 port is available.

If your database does not contain `kilowhat_rich_embeds` rows, this extension
does nothing.

## What it does

- Reads existing rows from `kilowhat_rich_embeds` and
  `kilowhat_rich_embed_post`.
- Renders a Discord/Slack-style preview card immediately after each matching
  link in a post body.
- Theme-aware (light/dark) via Flarum's CSS custom properties.
- Mobile-responsive (thumbnail stacks above text below 480px).
- Idempotent on Mithril re-renders (one card per unique URL per post).

It deliberately filters out:

- URLs whose embed `mime` starts with `image/` — Flarum already inlines
  images.
- YouTube hosts (`youtube.com`, `youtu.be`, `youtube-nocookie.com`) — Flarum
  2.0 already auto-embeds these.
- Embed rows where `http_status != 200` or `error IS NOT NULL`.
- Embed rows whose pivot has `is_link = 0` (1.x's "rich" embeds — usually
  bare media URLs that already render as `<img>`).

## What it does NOT do

- No `INSERT`, `UPDATE`, or `DELETE` against any database table.
- No URL scraping or metadata fetching.
- No new tables, columns, or migrations.
- No new settings rows.
- No image proxy — third-party images are hot-linked directly. Card images
  that fail to load (404, CORS, mixed-content) are removed via an `onerror`
  handler so the card degrades to text-only rather than showing a broken
  icon.

## Install

Add a VCS repository entry to your Flarum project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/ekumanov/flarum-ext-rich-embeds-display"
  }
]
```

Then:

```bash
composer require ekumanov/flarum-ext-rich-embeds-display:dev-main
php flarum extension:enable ekumanov-rich-embeds-display
php flarum cache:clear
```

## Removing it (when the official 2.0 port ships)

```bash
php flarum extension:disable ekumanov-rich-embeds-display
composer remove ekumanov/flarum-ext-rich-embeds-display
composer require kilowhat/rich-embeds
php flarum extension:enable kilowhat-rich-embeds
php flarum cache:clear
```

The `kilowhat_rich_embeds` and `kilowhat_rich_embed_post` tables stay in
place — the official extension will reuse them. Removal of this extension
leaves no residue in the database.

## Compatibility

- Flarum `^2.0`
- PHP `^8.2`

## License

MIT — see [LICENSE](LICENSE).

The original `kilowhat/rich-embeds` is a paid product. This package contains
no source code from it; it is an independent reimplementation of a
read-only render layer over the public database schema. If you intend to
add scraping, refresh, or other write functionality, please buy the real
thing rather than extending this.
