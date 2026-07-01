<?php

declare(strict_types=1);

/**
 * Issue #14448 — DOMNode::compareDocumentPosition() bitmask parity (ext/dom/node.c).
 */

$doc = new DOMDocument();
$doc->loadXML('<root><parent><child/></parent><sibling/></root>');
$root = $doc->documentElement;
$parent = $doc->getElementsByTagName('parent')->item(0);
$child = $doc->getElementsByTagName('child')->item(0);
$sibling = $doc->getElementsByTagName('sibling')->item(0);

$containsPreceding = DOMNode::DOCUMENT_POSITION_CONTAINS | DOMNode::DOCUMENT_POSITION_PRECEDING;
$containedFollowing = DOMNode::DOCUMENT_POSITION_CONTAINED_BY | DOMNode::DOCUMENT_POSITION_FOLLOWING;

if (0 !== $parent->compareDocumentPosition($parent)) {
    fwrite(STDERR, "fail: same node should be 0\n");
    exit(1);
}
if ($containsPreceding !== $parent->compareDocumentPosition($child)) {
    fwrite(STDERR, 'fail: parent vs child expected '.$containsPreceding.', got '.$parent->compareDocumentPosition($child)."\n");
    exit(1);
}
if ($containedFollowing !== $child->compareDocumentPosition($parent)) {
    fwrite(STDERR, 'fail: child vs parent expected '.$containedFollowing.', got '.$child->compareDocumentPosition($parent)."\n");
    exit(1);
}
if (DOMNode::DOCUMENT_POSITION_PRECEDING !== $parent->compareDocumentPosition($sibling)) {
    fwrite(STDERR, "fail: earlier branch should precede sibling\n");
    exit(1);
}
if (DOMNode::DOCUMENT_POSITION_FOLLOWING !== $sibling->compareDocumentPosition($parent)) {
    fwrite(STDERR, "fail: sibling should follow earlier branch\n");
    exit(1);
}

$doc2 = new DOMDocument();
$doc2->loadXML('<other/>');
$foreign = $doc2->documentElement;
$disconnected = $root->compareDocumentPosition($foreign);
if (0 === ($disconnected & DOMNode::DOCUMENT_POSITION_DISCONNECTED)) {
    fwrite(STDERR, "fail: cross-document compare should set DISCONNECTED\n");
    exit(1);
}
if (0 === ($disconnected & DOMNode::DOCUMENT_POSITION_IMPLEMENTATION_SPECIFIC)) {
    fwrite(STDERR, "fail: cross-document compare should set IMPLEMENTATION_SPECIFIC\n");
    exit(1);
}

echo "ok\n";
