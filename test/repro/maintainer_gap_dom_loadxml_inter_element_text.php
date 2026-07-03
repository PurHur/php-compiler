<?php
declare(strict_types=1);
$doc = new DOMDocument();
$doc->loadXML('<r>before<e/>after</r>');
$parts = [];
foreach ($doc->documentElement->childNodes as $n) {
    $parts[] = ($n->nodeType === XML_TEXT_NODE ? 't:' : 'e:').$n->nodeName;
}
$expected = ['t:#text', 'e:e', 't:#text'];
if ($parts !== $expected) {
    fwrite(STDERR, 'fail: child sequence '.var_export($parts, true).' expected '.var_export($expected, true)."\n");
    exit(1);
}
$roundTrip = $doc->saveXML($doc->documentElement);
if (!str_contains($roundTrip, 'before') || !str_contains($roundTrip, 'after')) {
    fwrite(STDERR, "fail: saveXML round-trip lost mixed content: {$roundTrip}\n");
    exit(1);
}
echo "ok\n";
