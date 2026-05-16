<?php

// Idempotent. Three install scenarios this needs to handle:
//
//   1. Brand-new install: creates `ekumanov_rich_embeds` and
//      `ekumanov_rich_embed_post` directly with the v2.x schema.
//
//   2. Upgrade from extension v1.x (where the tables were named
//      `kilowhat_*` for backwards-compat with kilowhat 1.x): tables already
//      exist under the old name; this migration is a no-op. The 030000
//      rename migration handles the rename to `ekumanov_*` later in the run.
//
//   3. Migrating from kilowhat 1.x directly: same as (2) — the kilowhat
//      tables exist, this migration is a no-op, the rename migration
//      handles bringing them under our namespace.

use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('ekumanov_rich_embeds') && ! $schema->hasTable('kilowhat_rich_embeds')) {
            $schema->create('ekumanov_rich_embeds', function (Blueprint $table) {
                $table->increments('id');
                $table->string('url', 2048);
                $table->binary('url_hash', 20)->nullable()->unique();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('error', 255)->nullable();
                $table->json('opengraph')->nullable();
                $table->json('icons')->nullable();
                $table->json('fallback')->nullable();
                $table->timestamp('created_at');
                $table->timestamp('retrieved_at')->nullable();
                $table->string('final_url', 2048)->nullable();
                $table->string('mime', 64)->nullable();
                $table->json('exif')->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->json('api_resource')->nullable();
            });
        }

        if (! $schema->hasTable('ekumanov_rich_embed_post') && ! $schema->hasTable('kilowhat_rich_embed_post')) {
            $schema->create('ekumanov_rich_embed_post', function (Blueprint $table) {
                $table->unsignedInteger('embed_id');
                $table->unsignedInteger('post_id');
                $table->boolean('is_link')->default(true);
                // dismissed_at: NULL=active, set by author/mod to hide the card
                // while keeping the link itself.
                $table->timestamp('dismissed_at')->nullable();
                $table->primary(['embed_id', 'post_id']);
                $table->index('post_id');
                $table->foreign('embed_id')->references('id')->on('ekumanov_rich_embeds')->onDelete('cascade');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        }
    },
    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        // Down is a no-op on purpose: dropping these tables would destroy
        // accumulated user data — every cached embed and every post→embed
        // pivot. If you really want them gone, drop manually.
    },
];
