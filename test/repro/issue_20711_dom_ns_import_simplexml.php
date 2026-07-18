<?php

declare(strict_types=1);

// #20711 — Dom\import_simplexml namespaced bridge (php-src ext/dom/php_dom.c).

echo 'legacy=', function_exists('dom_import_simplexml') ? '1' : '0', "\n";
echo 'Dom=', function_exists('Dom\\import_simplexml') ? '1' : '0', "\n";

if (!function_exists('Dom\\import_simplexml')) {
    fwrite(STDERR, "fail: Dom\\import_simplexml missing under PROFILE=8.4\n");
    exit(1);
}

$sxe = simplexml_load_string('<root><item id="1">a</item></root>');
if (false === $sxe) {
    fwrite(STDERR, "fail: simplexml_load_string\n");
    exit(1);
}

$dom = Dom\import_simplexml($sxe);
if (!($dom instanceof Dom\Element)) {
    fwrite(STDERR, 'fail: expected Dom\\Element, got '.get_debug_type($dom)."\n");
    exit(1);
}
if ('root' !== $dom->nodeName) {
    fwrite(STDERR, 'fail: expected root, got '.$dom->nodeName."\n");
    exit(1);
}
$item = $dom->getElementsByTagName('item')->item(0);
if (null === $item || 'a' !== $item->textContent || '1' !== $item->getAttribute('id')) {
    fwrite(STDERR, "fail: nested item mismatch\n");
    exit(1);
}

echo 'class=', get_class($dom), "\n";
echo 'name=', $dom->nodeName, "\n";
echo 'item=', $item->textContent, "\n";
echo "ok\n";
