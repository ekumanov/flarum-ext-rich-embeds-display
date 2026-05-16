<?php
// Integration smoke test for LocalDiscussionResolver against the mirror's
// real DB. Verifies that self-link URLs resolve to OG-shaped data with the
// discussion's title and a first-post excerpt.
require '/var/www/flarum/vendor/autoload.php';
$site = require '/var/www/flarum/site.php';
$server = $site->bootApp();
$server->getContainer()->make('flarum')->boot();
$c = $server->getContainer();

$resolver = $c->make(\Ekumanov\RichEmbedsDisplay\LocalDiscussion\LocalDiscussionResolver::class);

echo "=== resolve() against the mirror's real discussions ===\n";
foreach ([
    'http://localhost:8081/d/9',
    'http://localhost:8081/d/9-blacklist-test',
    'http://localhost:8081/d/12-mod-view-test',
    'http://localhost:8081/d/12-mod-view-test/1',
    'http://localhost:8081/d/99999-nonexistent',
    'https://example.com/d/9',
    'http://localhost:8081/u/admin',
] as $url) {
    $r = $resolver->resolve($url);
    if ($r === null) {
        echo "  $url → null\n";
    } else {
        $title = $r['title'];
        $desc = $r['description'] ? substr($r['description'], 0, 60).'…' : '(none)';
        echo "  $url\n    title=\"$title\"\n    desc=\"$desc\"\n    site=\"{$r['site_name']}\"\n";
    }
}
echo "\n=== done ===\n";
