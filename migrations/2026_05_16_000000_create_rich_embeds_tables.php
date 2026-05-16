<?php

// Idempotent — uses createTableIfNotExists so existing kilowhat 1.x installs
// (where the tables already exist from the original extension) keep their
// data untouched. Fresh 2.0 installs get the same schema.

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function (\Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasTable('kilowhat_rich_embeds')) {
            $schema->create('kilowhat_rich_embeds', function (Blueprint $table) {
                $table->increments('id');
                $table->string('url', 2048);
                $table->binary('url_hash', 20)->nullable()->unique();
                // SMALLINT — kilowhat 1.x used TINYINT but that silently
                // truncates 4xx/5xx codes to 255. Fresh installs get the
                // correct width; the 010000 migration widens existing tables.
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

        if (! $schema->hasTable('kilowhat_rich_embed_post')) {
            $schema->create('kilowhat_rich_embed_post', function (Blueprint $table) {
                $table->unsignedInteger('embed_id');
                $table->unsignedInteger('post_id');
                $table->boolean('is_link')->default(true);
                // dismissed_at: NULL=active, set by author/mod to hide the card
                // while keeping the link itself. Fresh installs get the wide
                // schema directly; the 020000 migration adds it to existing
                // kilowhat 1.x tables.
                $table->timestamp('dismissed_at')->nullable();
                $table->primary(['embed_id', 'post_id']);
                $table->index('post_id');
                $table->foreign('embed_id')->references('id')->on('kilowhat_rich_embeds')->onDelete('cascade');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        }
    },
    'down' => function (\Illuminate\Database\Schema\Builder $schema) {
        // Down is a no-op on purpose: dropping these tables on prod would
        // delete user data accumulated by the kilowhat 1.x extension and our
        // own subsequent fetches. If you really want them gone, drop manually.
    },
];
