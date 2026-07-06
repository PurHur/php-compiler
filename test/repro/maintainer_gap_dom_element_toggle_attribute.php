<?php

declare(strict_types=1);

$dom = new DOMDocument();
$el = $dom->createElement('p');
$dom->appendChild($el);

if (!$el->toggleAttribute('hidden')) {
    fwrite(STDERR, "first toggle should return true\n");
    exit(1);
}
if (!$el->hasAttribute('hidden')) {
    fwrite(STDERR, "hidden should be present after first toggle\n");
    exit(1);
}
if ($el->toggleAttribute('hidden')) {
    fwrite(STDERR, "second toggle should return false\n");
    exit(1);
}
if ($el->hasAttribute('hidden')) {
    fwrite(STDERR, "hidden should be absent after second toggle\n");
    exit(1);
}
if (!$el->toggleAttribute('hidden', true)) {
    fwrite(STDERR, "force=true on missing should add and return true\n");
    exit(1);
}
if ($el->toggleAttribute('hidden', false)) {
    fwrite(STDERR, "force=false on present should remove and return false\n");
    exit(1);
}

echo "ok\n";
