<?php

declare(strict_types=1);

// #29030 — Dom\TokenList classList under PROFILE=8.4 (error strings contain "class after ").
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "Dom\\TokenList / Dom\\HTMLDocument missing (requires PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d"></div></body></html>'
);
$el = $html->getElementById('d');

$el->classList->add('a', 'b');
if ($el->getAttribute('class') !== 'a b') {
    fwrite(STDERR, 'class after add expected "a b", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$el->classList->contains('a')) {
    fwrite(STDERR, "contains('a') should be true\n");
    exit(1);
}
if ($el->classList->length !== 2) {
    fwrite(STDERR, 'length expected 2, got ' . $el->classList->length . "\n");
    exit(1);
}
if ($el->classList->item(0) !== 'a' || $el->classList->item(1) !== 'b') {
    fwrite(STDERR, "item() mismatch\n");
    exit(1);
}
if (!$el->classList->toggle('c')) {
    fwrite(STDERR, "toggle('c') should return true\n");
    exit(1);
}
if ($el->getAttribute('class') !== 'a b c') {
    fwrite(STDERR, 'class after toggle expected "a b c", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}
$el->classList->remove('b');
if ($el->getAttribute('class') !== 'a c') {
    fwrite(STDERR, 'class after remove expected "a c", got ' . var_export($el->getAttribute('class'), true) . "\n");
    exit(1);
}

echo "ok\n";
