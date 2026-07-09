<?php

declare(strict_types=1);

$xml = '<root><item id="1">a</item><item id="2">b</item></root>';
$doc = new DOMDocument();
$doc->loadXML($xml);
if (!class_exists('DOMXPath', false)) {
    fwrite(STDERR, "fail: DOMXPath class missing\n");
    exit(1);
}
$xpath = new DOMXPath($doc);
$nodes = $xpath->query('//item[@id="2"]');
if (1 !== $nodes->length) {
    fwrite(STDERR, "fail: expected 1 node, got ".$nodes->length."\n");
    exit(1);
}
if ('b' !== $nodes->item(0)->textContent) {
    fwrite(STDERR, "fail: expected textContent b, got ".$nodes->item(0)->textContent."\n");
    exit(1);
}

echo "ok\n";
