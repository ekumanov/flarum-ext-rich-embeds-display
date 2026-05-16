import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';

const CARD_CLASS = 'RichEmbedsDisplay-card';
const PLACEHOLDER_CLASS = 'RichEmbedsDisplay-restorePlaceholder';
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

    // Signature includes the dismissed flag and canDismiss so a state change
    // (mod dismisses a card while page is open) triggers a re-render. URL
    // alone wouldn't pick that up.
    const sig = list.length ? list.map((e) => `${e.url}|${e.dismissed ? 'd' : ''}|${e.canDismiss ? 'm' : ''}`).join('') : '';

    if (body.getAttribute(SIG_ATTR) === sig) return;

    body.querySelectorAll('.' + CARD_CLASS + ', .' + PLACEHOLDER_CLASS).forEach((el) => el.remove());

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

        if (link.closest('.' + CARD_CLASS)) continue;

        const node = embed.dismissed ? buildRestorePlaceholder(embed) : buildCard(embed);
        link.parentNode.insertBefore(node, link.nextSibling);
    }

    body.setAttribute(SIG_ATTR, sig);
}

function buildCard(embed) {
    // The card itself is the click target; the dismiss button is layered on
    // top, with stopPropagation so clicking ✕ doesn't open the link.
    const wrapper = document.createElement('div');
    wrapper.className = CARD_CLASS + '-wrapper';

    const card = document.createElement('a');
    card.className = CARD_CLASS;
    card.href = embed.finalUrl || embed.url;
    card.target = '_blank';
    card.rel = 'nofollow noopener noreferrer';

    // Screen readers: collapse the multi-div content into a single descriptive
    // accessible name. Without this they announce site / title / desc as three
    // separate text nodes (verbose); with aria-label they read it as one link.
    // The "Link preview:" prefix tells the user this is a card (not the
    // original link), so they can choose to skip it if the post body's link
    // was already enough.
    const labelParts = [
        embed.siteName || embed.domain,
        embed.title,
        embed.description ? truncate(embed.description, DESC_MAX) : null,
    ].filter(Boolean);
    const previewLabel = app.translator.trans('ekumanov-rich-embeds-display.forum.preview_aria_prefix') || 'Link preview';
    card.setAttribute('aria-label', `${previewLabel}: ${labelParts.join(' — ')}`);

    if (embed.image) {
        const img = document.createElement('img');
        img.className = CARD_CLASS + '-image';
        img.src = embed.image;
        img.alt = ''; // decorative — text content provides the info
        img.loading = 'lazy';
        img.referrerPolicy = 'no-referrer';
        img.addEventListener('error', () => {
            const ph = document.createElement('div');
            ph.className = CARD_CLASS + '-image ' + CARD_CLASS + '-image--missing';
            ph.setAttribute('aria-hidden', 'true'); // empty placeholder; screen readers ignore
            img.replaceWith(ph);
        }, { once: true });
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
    wrapper.appendChild(card);

    if (embed.canDismiss) {
        wrapper.appendChild(buildDismissButton(embed, wrapper));
    }

    return wrapper;
}

function buildDismissButton(embed, wrapper) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = CARD_CLASS + '-dismiss';
    // The visible glyph is `×` but screen readers should hear "Hide preview"
    // — pair an aria-hidden glyph with an accessible label.
    const label = app.translator.trans('ekumanov-rich-embeds-display.forum.dismiss_preview') || 'Hide preview';
    btn.setAttribute('aria-label', label);
    btn.title = label;
    const glyph = document.createElement('span');
    glyph.setAttribute('aria-hidden', 'true');
    glyph.textContent = '×';
    btn.appendChild(glyph);
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        btn.disabled = true;
        sendDismiss(embed, 'POST')
            .then(() => {
                const ph = buildRestorePlaceholder({ ...embed, dismissed: true });
                wrapper.replaceWith(ph);
                // Move focus to the restore button so keyboard / screen-reader
                // users don't lose their place when the card collapses.
                const restoreBtn = ph.querySelector('.' + PLACEHOLDER_CLASS + '-restore');
                if (restoreBtn) restoreBtn.focus();
            })
            .catch(() => {
                btn.disabled = false;
            });
    });
    return btn;
}

function buildRestorePlaceholder(embed) {
    // Tiny one-line link only visible to actors with edit perm. Regular
    // readers' API response filters dismissed embeds out before this point,
    // so they never get to see this row at all.
    const ph = document.createElement('span');
    ph.className = PLACEHOLDER_CLASS;
    ph.setAttribute('role', 'status'); // mild aria-live so SR users notice the swap

    const label = document.createElement('span');
    label.className = PLACEHOLDER_CLASS + '-label';
    label.textContent = app.translator.trans('ekumanov-rich-embeds-display.forum.preview_dismissed') || 'Preview dismissed';
    ph.appendChild(label);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = PLACEHOLDER_CLASS + '-restore';
    const restoreText = app.translator.trans('ekumanov-rich-embeds-display.forum.restore_preview') || 'Show preview again';
    btn.textContent = '▸ ' + restoreText;
    // Pair the visible label with a more specific accessible name including
    // the URL, so a SR user knows which preview will return.
    btn.setAttribute('aria-label', `${restoreText}: ${embed.url}`);
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        btn.disabled = true;
        sendDismiss(embed, 'DELETE')
            .then(() => {
                const card = buildCard({ ...embed, dismissed: false });
                ph.replaceWith(card);
                // Move focus to the restored card link so keyboard users
                // don't fall back to the document top.
                const cardLink = card.querySelector('.' + CARD_CLASS);
                if (cardLink) cardLink.focus();
            })
            .catch(() => {
                btn.disabled = false;
            });
    });
    ph.appendChild(btn);

    return ph;
}

function sendDismiss(embed, method) {
    return app.request({
        method,
        url: `${app.forum.attribute('apiUrl')}/rich-embeds/posts/${embed.postId}/embeds/${embed.embedId}/dismiss`,
    });
}

function truncate(s, max) {
    if (!s) return '';
    const trimmed = s.trim();
    if (trimmed.length <= max) return trimmed;
    return trimmed.slice(0, max - 1).replace(/\s+\S*$/, '') + '…';
}
