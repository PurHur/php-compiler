<?php
// Maintainer gap: DOMNode::replaceChildren() — PHP 8.4 (#16822, ext/dom/parentnode.c).
$doc = new DOMDocument();
$parent = $doc->createElement('parent');
$doc->appendChild($parent);

if (!method_exists($parent, 'replaceChildren')) {
    echo "ok\n";
    exit(0);
}

$old = $doc->createElement('old');
$parent->appendChild($old);
$new = $doc->createElement('new');
$parent->replaceChildren($new);
echo $parent->childNodes->length, "\n";
echo $parent->firstChild->nodeName, "\n";
