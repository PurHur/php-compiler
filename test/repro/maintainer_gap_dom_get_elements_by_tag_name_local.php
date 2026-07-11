<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML(
    '<?xml version="1.0"?>'
    .'<root xmlns:ex="http://example.com/ns">'
    .'<ex:child/><other/><ex:item/></root>'
);
$list = $doc->getElementsByTagName('child');
$count = $list->length;
if (1 !== $count) {
    fwrite(STDERR, "fail: expected 1 child node, got {$count}\n");
    exit(1);
}
$node = $list->item(0);
if ('child' !== $node->localName) {
    fwrite(STDERR, "fail: localName expected child, got {$node->localName}\n");
    exit(1);
}
$itemList = $doc->getElementsByTagName('item');
$item = $itemList->item(0);
if (null === $item || 'ex:item' !== $item->tagName) {
    fwrite(STDERR, 'fail: prefixed item tagName mismatch'."\n");
    exit(1);
}
$otherList = $doc->getElementsByTagName('other');
if (1 !== $otherList->length) {
    fwrite(STDERR, "fail: expected 1 other node, got {$otherList->length}\n");
    exit(1);
}

echo "ok\n";
