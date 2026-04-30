import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

const CARD_CLASS = 'RichEmbedsDisplay-card';
const SIG_ATTR = 'data-reb-sig';
const DESC_MAX = 220;

extend(CommentPost.prototype, 'oncreate', function () {
    processEmbeds(this);
});

extend(CommentPost.prototype, 'onupdate', function () {
    processEmbeds(this);
});

function processEmbeds(commentPost) {
    const post = commentPost.attrs && commentPost.attrs.post;
    if (!post || !post.attribute) return;

    const embeds = post.attribute('richEmbedsDisplay');
    const list = Array.isArray(embeds) ? embeds : [];

    const root = commentPost.element;
    if (!root) return;

    const body = root.querySelector('.Post-body');
    if (!body) return;

    const sig = list.length ? list.map((e) => e.url).join('') : '';

    if (body.getAttribute(SIG_ATTR) === sig) return;

    body.querySelectorAll('.' + CARD_CLASS).forEach((el) => el.remove());

    if (!list.length) {
        body.setAttribute(SIG_ATTR, sig);
        return;
    }

    const byUrl = new Map();
    for (const embed of list) {
        if (embed && embed.url) byUrl.set(embed.url, embed);
    }

    const seen = new Set();
    const links = body.querySelectorAll('a[href]');
    for (const link of links) {
        const href = link.getAttribute('href');
        if (!href) continue;
        const embed = byUrl.get(href);
        if (!embed) continue;
        if (seen.has(embed.url)) continue;
        seen.add(embed.url);

        // Skip if the link is itself nested inside a card we already injected
        // (defensive: shouldn't happen, but keeps re-runs idempotent).
        if (link.closest('.' + CARD_CLASS)) continue;

        const card = buildCard(embed);
        link.parentNode.insertBefore(card, link.nextSibling);
    }

    body.setAttribute(SIG_ATTR, sig);
}

function buildCard(embed) {
    const card = document.createElement('a');
    card.className = CARD_CLASS;
    card.href = embed.finalUrl || embed.url;
    card.target = '_blank';
    card.rel = 'nofollow noopener noreferrer';

    if (embed.image) {
        const img = document.createElement('img');
        img.className = CARD_CLASS + '-image';
        img.src = embed.image;
        img.alt = '';
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        // If a hot-linked image fails (CORS, mixed content, 404), drop the slot rather
        // than leaving a broken-image icon in the card.
        img.addEventListener('error', () => img.remove(), { once: true });
        card.appendChild(img);
    }

    const text = document.createElement('div');
    text.className = CARD_CLASS + '-text';

    const site = document.createElement('div');
    site.className = CARD_CLASS + '-site';
    site.textContent = embed.siteName || embed.domain || '';
    text.appendChild(site);

    const title = document.createElement('div');
    title.className = CARD_CLASS + '-title';
    title.textContent = embed.title;
    text.appendChild(title);

    if (embed.description) {
        const desc = document.createElement('div');
        desc.className = CARD_CLASS + '-desc';
        desc.textContent = truncate(embed.description, DESC_MAX);
        text.appendChild(desc);
    }

    card.appendChild(text);
    return card;
}

function truncate(s, max) {
    if (!s) return '';
    const trimmed = s.trim();
    if (trimmed.length <= max) return trimmed;
    return trimmed.slice(0, max - 1).replace(/\s+\S*$/, '') + '…';
}
