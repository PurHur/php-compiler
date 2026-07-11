<?php
declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root xmlns:ex="http://example.com/ns"><item ex:id="target"/></root>');
$item = $doc->getElementsByTagName('item')->item(0);
if (null === $item) {
    fwrite(STDERR, "FAIL: item element missing\n");
    exit(1);
}
if (null !== $doc->getElementById('target')) {
    fwrite(STDERR, "FAIL: getElementById before setIdAttributeNS should be null\n");
    exit(1);
}
$item->setIdAttributeNS('http://example.com/ns', 'id', true);
$found = $doc->getElementById('target');
if (null === $found) {
    fwrite(STDERR, "FAIL: getElementById after setIdAttributeNS returned null\n");
    exit(1);
}
echo "OK\n";
