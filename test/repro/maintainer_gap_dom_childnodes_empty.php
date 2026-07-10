<?php

declare(strict_types=1);

$doc = new DOMDocument();
$children = $doc->childNodes;
if (!($children instanceof DOMNodeList)) {
    fwrite(STDERR, 'fail: childNodes must be DOMNodeList object on empty document, got '.get_debug_type($children)."\n");
    exit(1);
}
if (0 !== $children->length) {
    fwrite(STDERR, 'fail: empty document childNodes length must be 0, got '.$children->length."\n");
    exit(1);
}

$frag = new DOMDocumentFragment();
$fragChildren = $frag->childNodes;
if (!($fragChildren instanceof DOMNodeList)) {
    fwrite(STDERR, 'fail: childNodes must be DOMNodeList object on empty fragment, got '.get_debug_type($fragChildren)."\n");
    exit(1);
}
if (0 !== $fragChildren->length) {
    fwrite(STDERR, 'fail: empty fragment childNodes length must be 0, got '.$fragChildren->length."\n");
    exit(1);
}

$el = $doc->createElement('x');
$elChildren = $el->childNodes;
if (!($elChildren instanceof DOMNodeList)) {
    fwrite(STDERR, 'fail: childNodes must be DOMNodeList object on empty element, got '.get_debug_type($elChildren)."\n");
    exit(1);
}
if (0 !== $elChildren->length) {
    fwrite(STDERR, 'fail: empty element childNodes length must be 0, got '.$elChildren->length."\n");
    exit(1);
}

echo "OK\n";
