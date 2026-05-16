# Rich Embeds for Flarum 2.0

Renders Discord/Slack-style preview cards for plain `<a href>` links in posts.
Fetches OpenGraph / Twitter Card metadata server-side, caches it, and renders
the result client-side without any layout shift.

A clean-room reimplementation of the link-card behaviour offered by
[`kilowhat/rich-embeds`](https://floxum.com/extension/kilowhat/rich-embeds)
(which never received a 2.0 port). Schema-compatible with that extension's
existing tables so installs migrating from 1.x see no data loss.

Scope is deliberately narrow: regular links only. YouTube auto-embeds are
already handled by Flarum 2.0 core; bare-image URLs are already inlined by
the formatter; this extension fills the remaining gap.

## How it works

```
POST /api/posts (synchronous)                       Background worker
─────────────────────────                          ─────────────────────
1. Flarum saves post.                              1. Pops FetchEmbedJob.
2. Posted/Revised event fires.                     2. SafeHttpClient.get()
3. ScanPostUrls listener:                              (≤10s budget,
   - DOMs the rendered body for <a href>             SSRF-hardened).
   - dedups / validates / whitelist / blacklist    3. OG + fallback parsers.
   - rate-limits per author (hourly)               4. Updates the embed row.
   - inserts placeholder embed + pivot rows
   - queue→push(new FetchEmbedJob)
4. API response returns in normal ~50ms.           5. Next page load
                                                       renders the card.
```

The post-save request thread never blocks on a remote fetch. If the queue is
backed up or a worker is down, posts still go in immediately; cards just
appear later as the worker drains. A scheduler sweep (5-minute interval)
re-dispatches any rows that lost their job.

## Security model

URL fetching is unusually dangerous — getting it wrong leaks internal
network access (SSRF), exposes cloud metadata services, or hands an
attacker a bandwidth amplifier. This extension layers eleven defenses:

| # | Defense                                       | What it stops                            |
|---|-----------------------------------------------|------------------------------------------|
| 1 | URL allowlisted schemes (http/https only)     | `file://`, `gopher://`, `javascript:`    |
| 2 | URL allowlisted ports (80/443)                | SSH probes, port-scan-by-redirect        |
| 3 | URL rejects credentials in URL                | Forwarded auth leak                      |
| 4 | DNS resolves A + AAAA, every IP filtered      | Naïve filter bypass via AAAA             |
| 5 | IP filter: RFC1918/loopback/link-local/ULA/multicast/test-net + v4-mapped IPv6 unwrap | All non-public address space, including `::ffff:127.0.0.1` |
| 6 | Reject host entirely if ANY resolved IP private | DNS rebinding (mixed public/private answers) |
| 7 | `CURLOPT_RESOLVE` pins the vetted IP for the actual connect | TOCTOU between resolve and connect |
| 8 | Manual redirect handling — every Location re-runs validation chain | Public-decoy → private-IP redirect    |
| 9 | `CURLOPT_PROTOCOLS_STR` locks the wire protocol | `http://` redirect to `dict://`         |
| 10 | `WRITEFUNCTION` returns -1 over 2 MB         | Slowloris / bandwidth flood              |
| 11 | Per-user hourly URL submission rate limit + per-post max URLs cap | Logged-in attacker abusing the fetch queue |

No retries on fetch failure — a single bad URL doesn't become a fetch storm.
Failed rows record the failure and stay failed until TTL expiry (default
30 days) plus a re-post by someone.

The full SSRF chain is exercised end-to-end by `tests/Integration/Http/SafeHttpClientLiveTest.php`
which hits `http://127.0.0.1/`, `http://localhost/`, and `http://169.254.169.254/`
against real curl and asserts they're all blocked.

### What this does NOT do (intentional)

- **No client-driven fetch.** Browsers never trigger fetches directly. The
  only trigger is a post being saved/edited by an authenticated user.
- **No retries.** Failed fetches stay failed (one entry per URL).
- **No image proxy.** OG thumbnails are hot-linked from the source. Failed
  image loads degrade to a fixed-size placeholder slot (no layout shift).
- **No YouTube card** — fof/formatting's `mediaembed` plugin renders YouTube
  URLs as iframe video players. We skip them at scan time.
- **No image-MIME card** — fof/formatting's `autoimage` plugin renders bare
  image URLs as inline `<img>` tags. We skip image-extension URLs at scan time.
- **No third-party API integrations** (Google Drive, GitHub).

## Dismissing a card

If a card looks bad (mediocre OG data, unwanted content, off-topic) the post
author or any moderator/admin can dismiss the individual card without
removing the link itself.

- Hover the card → a **✕** button appears in the top-right corner.
- Click → card disappears, replaced by an inline "Preview dismissed
  · Show preview again" hint visible only to authors/mods.
- Click "Show preview again" → card returns.

Dismissal is per `(post, embed)` — the same URL stays embeddable in other
posts. Stored in the `dismissed_at` column on the pivot table. Regular
readers never see dismissed cards; the API hides them upstream of the
serializer.

Permissions: `$actor->can('edit', $post)` — Flarum's standard policy, which
grants `discussion.editOwnPost` to the author and `discussion.editPost` to
mods/admins.

API:

```
POST   /api/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss   -> 204
DELETE /api/rich-embeds/posts/{postId}/embeds/{embedId}/dismiss   -> 204
```

## CLS (Cumulative Layout Shift) posture

Cards have fixed CSS dimensions:

- Desktop: 120 × 90 px thumbnail, title clamped to 2 lines, description to 3
- Mobile (≤480 px): 100% × 160 px thumbnail, stacked

Image loading doesn't reflow the card (the slot is reserved by CSS). Image
load failures swap to a same-dimension placeholder div instead of removing
the `<img>` outright — the card stays exactly as wide and tall as it was.

Two known soft-CLS edge cases:

1. **Worker-driven realtime updates** — if a forum has `flarum/realtime` and
   the post is being viewed live when the worker finishes a fetch, the post
   may re-render with the card freshly attached, shifting content below the
   post. This requires both extensions enabled, an open page when the
   fetch lands, and the realtime layer to actually re-push the post (which
   it doesn't do for embed-only changes today). In practice this is rare.
2. **First-paint of a freshly-edited post** — same scenario, but for the
   editor: when they save a post with a new URL, the response comes back
   before the worker has fetched. The poster sees no card until they reload.
   No shift in their view (the card was never there), but a different layout
   than someone visiting the same post 10 s later. This is consistent with
   how Flarum's other deferred-content patterns (mention notifications,
   search reindex) behave.

A placeholder-card render path (PostResourceFields returns a `pending: true`
marker, JS draws a fixed-height skeleton) would close case 1 fully — see
"Future work" below.

## Install

This will not appear on Packagist; install via the VCS repo on GitHub.

`composer.json`:

```json
"repositories": [
  { "type": "vcs", "url": "https://github.com/ekumanov/flarum-ext-rich-embeds-display" }
]
```

Then:

```bash
composer require ekumanov/flarum-ext-rich-embeds-display:dev-main
php flarum migrate
php flarum extension:enable ekumanov-rich-embeds-display
php flarum cache:clear
```

### Queue worker required

This extension dispatches background jobs. Flarum's default queue driver is
`sync` (run inline in the request thread) which **defeats the entire async
design**. Configure a real queue:

- **Redis** via [`fof/redis`](https://github.com/FriendsOfFlarum/redis) is what
  pianoclack.com uses. Install + configure, then ensure `php flarum queue:work`
  runs as a supervised daemon.
- **Database** queue works too (slower but no Redis dep).

If the queue stays `sync`, the post-save request will hang on the fetch.

### Scheduler

The 5-minute sweep needs `php flarum schedule:run` running via a system cron:

```cron
* * * * * cd /path/to/flarum && php flarum schedule:run >> /dev/null 2>&1
```

Without this the sweep won't fire — but it's only a fallback for dropped
jobs, so a forum without it just loses the safety net.

## Configuration

Settings live under the `ekumanov-rich-embeds.` prefix in the `settings`
table. No admin UI yet (planned for v1.1); set directly:

```sql
INSERT INTO settings (`key`, value) VALUES
  ('ekumanov-rich-embeds.ttl_seconds',         '2592000'),   -- 30 days
  ('ekumanov-rich-embeds.user_rate_per_hour',  '20'),
  ('ekumanov-rich-embeds.max_urls_per_post',   '10'),
  ('ekumanov-rich-embeds.whitelist',           ''),
  ('ekumanov-rich-embeds.blacklist',           'amazon.com,*.amazon.com,ebay.com,*.ebay.com');
ON DUPLICATE KEY UPDATE value = VALUES(value);
```

### `whitelist` / `blacklist`

Comma-, space-, or semicolon-separated hostnames. Case-insensitive.

- The `www.` prefix is normalised both ways — `amazon.com` matches
  `www.amazon.com` and vice versa.
- Subdomain wildcards: `*.amazon.com` matches `smile.amazon.com`,
  `prime.amazon.com`, but NOT bare `amazon.com` — add that as a
  separate entry to catch the apex.
- `whitelist` is enforced *before* `blacklist`. If `whitelist` is set,
  hosts outside it are excluded; blacklist filters within whatever
  remains.

Default blacklist is **empty** — every URL gets a fetch + card unless the
admin curates exclusions. (Other extensions' formatters render specific
hosts as iframe embeds — YouTube via fof/formatting's MediaEmbed for
instance — those are skipped at scan-time unconditionally so we never
compete with the formatter, regardless of blacklist settings.)

Admins/authors can still dismiss individual cards on a per-post basis
via the ✕ button on each card — that's the right tool for "this
specific card is ugly", whereas the blacklist is for "I never want
cards from this host."

## Migration from `kilowhat/rich-embeds` 1.x

If your forum ran the 1.x kilowhat extension, the tables
(`kilowhat_rich_embeds`, `kilowhat_rich_embed_post`) and old data are
preserved as-is. This extension reuses them in place. Old cards render
identically. New posts trigger fetches via the new worker pipeline.

The old `kilowhat-rich-embeds.*` settings rows are ignored — this extension
reads from `ekumanov-rich-embeds.*` and falls back to defaults.

## Development

```bash
# PHP unit + integration tests
composer install
vendor/bin/phpunit

# JS build
cd js && npm install && npm run build

# Live smoke against a running Flarum container (see prod-mirror docs)
docker exec <php-container> php /path/to/extension/tests/smoke.php
bash tests/smoke-listener.sh
```

## Future work (v1.1+)

- Admin settings UI (blacklist/whitelist/TTL textareas)
- Per-group permission gating (`ekumanov-rich-embeds.useOnOwnPost`)
- Placeholder-card render path closing the realtime-update CLS edge case
- Strike-based auto-mute (mirroring the cls-fix pattern) when a user posts
  many private-IP URLs in succession
- Optional image proxy for hot-link reliability + privacy
- Search reindex hook for cards (so OG title/description are searchable)

## License

MIT — see [LICENSE](LICENSE).

The original `kilowhat/rich-embeds` is a paid product. This package contains
no source code from it; it's an independent reimplementation based on the
public OpenGraph spec and the legacy table schema. If you'd rather pay for
the official 2.0 port whenever it ships, buy it instead.
