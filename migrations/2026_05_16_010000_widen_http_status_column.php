<?php

// kilowhat 1.x declared `http_status` as TINYINT UNSIGNED — fine for 2xx
// (e.g. 200) but silently TRUNCATES 4xx / 5xx codes to 255 because that's
// the column max. Widen to SMALLINT UNSIGNED so the actual HTTP status is
// stored verbatim, which makes failures debuggable.
//
// Idempotent: if the column is already wide enough we leave it alone.

use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('kilowhat_rich_embeds')) {
            return; // create-table migration will declare the wide column directly
        }

        $col = $schema->getConnection()->selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'kilowhat_rich_embeds'
               AND COLUMN_NAME = 'http_status'"
        );

        if ($col !== null && strtolower((string) $col->DATA_TYPE) === 'tinyint') {
            $schema->getConnection()->statement(
                'ALTER TABLE `kilowhat_rich_embeds`
                 MODIFY COLUMN `http_status` SMALLINT UNSIGNED NULL'
            );
        }
    },
    'down' => function (Builder $schema) {
        // No-op: narrowing back would truncate legitimate 4xx/5xx codes.
    },
];
