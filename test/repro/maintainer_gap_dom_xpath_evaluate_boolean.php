<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><child id="1">a</child></root>');
$xpath = new DOMXPath($doc);

$bool = $xpath->evaluate('boolean(//child)');
if (!is_bool($bool) || !$bool) {
    fwrite(STDERR, 'fail: boolean(//child) expected true bool, got '.get_debug_type($bool)."\n");
    exit(1);
}

$count = $xpath->evaluate('count(//child)');
if (!is_int($count) && !is_float($count)) {
    fwrite(STDERR, 'fail: count(//child) expected number, got '.get_debug_type($count)."\n");
    exit(1);
}
if (1 !== (int) $count) {
    fwrite(STDERR, "fail: count(//child) expected 1, got {$count}\n");
    exit(1);
}

echo "ok\n";
