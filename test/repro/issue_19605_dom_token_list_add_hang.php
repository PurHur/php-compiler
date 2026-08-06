<?php

declare(strict_types=1);

// #19605 — Dom\TokenList add/remove/toggle/replace must not hang
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    fwrite(STDERR, "Dom\\TokenList / Dom\\HTMLDocument missing (requires PHP_COMPILER_PROFILE=8.4)\n");
    exit(1);
}

$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><e class="a" id="e"></e></body></html>'
);
$e = $html->getElementById('e');
$e->classList->add('b');
if ($e->getAttribute('class') !== 'a b') {
    fwrite(STDERR, 'add failed: ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
$e->classList->remove('a');
if ($e->getAttribute('class') !== 'b') {
    fwrite(STDERR, 'remove failed: ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$e->classList->toggle('c')) {
    fwrite(STDERR, "toggle('c') should return true\n");
    exit(1);
}
if ($e->getAttribute('class') !== 'b c') {
    fwrite(STDERR, 'toggle failed: ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
if (!$e->classList->replace('b', 'd')) {
    fwrite(STDERR, "replace('b','d') should return true\n");
    exit(1);
}
if ($e->getAttribute('class') !== 'd c') {
    fwrite(STDERR, 'replace failed: ' . var_export($e->getAttribute('class'), true) . "\n");
    exit(1);
}
echo "ok\n";
