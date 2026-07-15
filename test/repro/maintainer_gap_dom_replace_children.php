<?php
// Maintainer gap: DOMNode::replaceChildren() — PHP 8.4 (#16822, ext/dom/parentnode.c).
// Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_dom_replace_children.php
$doc = new DOMDocument();
$parent = $doc->createElement('parent');
$doc->appendChild($parent);

$old = $doc->createElement('old');
$parent->appendChild($old);
$new = $doc->createElement('new');
$parent->replaceChildren($new);
echo $parent->childNodes->length, "\n";
echo $parent->firstChild->nodeName, "\n";
