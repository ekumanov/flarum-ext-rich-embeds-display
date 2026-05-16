<?php

// Adds dismissed_at to the pivot table so post authors / mods can
// suppress a specific (post, embed) pair's card render. NULL = active,
// timestamp = dismissed. The column is on the pivot (not the embeds
// table) because the same URL can be dismissed in one post but kept in
// another.
//
// Idempotent — safe to run against existing kilowhat 1.x tables on prod.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('kilowhat_rich_embed_post')) {
            return; // create-table migration handles fresh installs
        }
        if (! $schema->hasColumn('kilowhat_rich_embed_post', 'dismissed_at')) {
            $schema->table('kilowhat_rich_embed_post', function (Blueprint $table) {
                $table->timestamp('dismissed_at')->nullable();
            });
        }
    },
    'down' => function (Builder $schema) {
        if ($schema->hasTable('kilowhat_rich_embed_post') && $schema->hasColumn('kilowhat_rich_embed_post', 'dismissed_at')) {
            $schema->table('kilowhat_rich_embed_post', function (Blueprint $table) {
                $table->dropColumn('dismissed_at');
            });
        }
    },
];
