<?php

namespace Ekumanov\RichEmbedsDisplay;

use Flarum\Database\AbstractModel;

class Embed extends AbstractModel
{
    protected $table = 'kilowhat_rich_embeds';

    public $timestamps = false;

    protected $casts = [
        'opengraph' => 'array',
        'icons' => 'array',
        'fallback' => 'array',
        'api_resource' => 'array',
        'exif' => 'array',
    ];
}
