<?php

// v2.0.0 schema cleanup: rename `kilowhat_rich_embeds` and
// `kilowhat_rich_embed_post` to the project-native `ekumanov_*` names.
//
// Why now: the original extension shipped as a read-only stopgap over
// existing kilowhat 1.x tables, so kept the names for backwards compat. As
// it grew into a full fetcher (v1.0.0+), the kilowhat branding became a
// historical leftover.
//
// MySQL's RENAME TABLE is atomic and preserves foreign-key constraints
// pointing into AND out of the renamed table. The pivot's FKs to
// kilowhat_rich_embeds.id get auto-rewired to ekumanov_rich_embeds.id.
//
// Idempotent:
//   - Both tables already renamed → no-op.
//   - Only kilowhat side exists (legacy upgrade) → rename.
//   - Only ekumanov side exists (fresh install) → no-op.
//   - Both sides exist (manual partial state) → throw, require human intervention.

use Illuminate\Database\Schema\Builder;
use RuntimeException;

return [
    'up' => function (Builder $schema) {
        $hasKilowhatEmbeds = $schema->hasTable('kilowhat_rich_embeds');
        $hasEkumanovEmbeds = $schema->hasTable('ekumanov_rich_embeds');
        if ($hasKilowhatEmbeds && $hasEkumanovEmbeds) {
            throw new RuntimeException(
                'Both kilowhat_rich_embeds and ekumanov_rich_embeds exist. '
                .'Resolve manually (consolidate or drop one) before re-running.'
            );
        }
        if ($hasKilowhatEmbeds && ! $hasEkumanovEmbeds) {
            $schema->rename('kilowhat_rich_embeds', 'ekumanov_rich_embeds');
        }

        $hasKilowhatPivot = $schema->hasTable('kilowhat_rich_embed_post');
        $hasEkumanovPivot = $schema->hasTable('ekumanov_rich_embed_post');
        if ($hasKilowhatPivot && $hasEkumanovPivot) {
            throw new RuntimeException(
                'Both kilowhat_rich_embed_post and ekumanov_rich_embed_post exist. '
                .'Resolve manually before re-running.'
            );
        }
        if ($hasKilowhatPivot && ! $hasEkumanovPivot) {
            $schema->rename('kilowhat_rich_embed_post', 'ekumanov_rich_embed_post');
        }
    },
    'down' => function (Builder $schema) {
        // No automatic rollback. Renaming back would be destructive and
        // shouldn't happen automatically — operator can rename manually if
        // really needed.
    },
];
