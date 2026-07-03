<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$parent = $doc->getElementsByTagName('parent')->item(0);
$sibling = $doc->getElementsByTagName('sibling')->item(0);

$pos = $parent->compareDocumentPosition($sibling);
$following = DOMNode::DOCUMENT_POSITION_FOLLOWING;
$preceding = DOMNode::DOCUMENT_POSITION_PRECEDING;

if (($pos & $following) === 0) {
    fwrite(STDERR, "fail (pos={$pos}, FOLLOWING bit unset)\n");
    exit(1);
}
if (($pos & $preceding) !== 0) {
    fwrite(STDERR, "fail (pos={$pos}, PRECEDING bit set unexpectedly)\n");
    exit(1);
}

echo "ok\n";
