<?php
/**
 * Parsedown-shaped PCRE probes (#36380): `\b` after `_` and `[^][]` + `(?R)`.
 */
$Em = '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us';
$link = '/\[((?:[^][]++|(?R))*+)\]/';
foreach ([
    ['em', $Em, '_underscore_'],
    ['link', $link, '[link](http://example.com)'],
    ['wb', '/abc\b/', 'abc'],
    ['class', '/[^][]+/', 'ab]c'],
] as [$name, $rx, $s]) {
    $ok = preg_match($rx, $s, $m);
    echo "$name ok=$ok m0=[" . ($m[0] ?? '') . "]\n";
}
