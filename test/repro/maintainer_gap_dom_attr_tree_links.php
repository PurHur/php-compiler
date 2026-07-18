<?php
/** Repro #20501 — DOMAttr parentNode / siblings / value text child. */
$doc = new DOMDocument();
$doc->loadXML('<r a="1" b="2" c="3"/>');
$el = $doc->documentElement;
$a = $el->getAttributeNode('a');
$b = $el->getAttributeNode('b');
$c = $el->getAttributeNode('c');

echo 'parent=', ($a->parentNode === $el) ? '1' : '0', "\n";
echo 'parent_is_owner=', ($a->parentNode === $a->ownerElement) ? '1' : '0', "\n";
echo 'a_next_b=', ($a->nextSibling === $b) ? '1' : '0', "\n";
echo 'b_prev_a=', ($b->previousSibling === $a) ? '1' : '0', "\n";
echo 'b_next_c=', ($b->nextSibling === $c) ? '1' : '0', "\n";
echo 'c_next=', ($c->nextSibling === null) ? 'null' : 'set', "\n";
echo 'firstChild=', ($a->firstChild === null) ? 'null' : (get_class($a->firstChild) . ':' . $a->firstChild->nodeValue), "\n";
echo 'childNodes=', (string) $a->childNodes->length, "\n";
echo 'hasChildNodes=', $a->hasChildNodes() ? '1' : '0', "\n";

$orphan = $doc->createAttribute('x');
echo 'orphan_has=', $orphan->hasChildNodes() ? '1' : '0', "\n";
$orphan->value = 'hi';
echo 'orphan_first=', ($orphan->firstChild === null) ? 'null' : (get_class($orphan->firstChild) . ':' . $orphan->firstChild->nodeValue), "\n";

$el->setAttributeNode($orphan);
echo 'attached_parent=', ($orphan->parentNode === $el) ? '1' : '0', "\n";
$el->removeAttributeNode($orphan);
echo 'removed_parent=', ($orphan->parentNode === null) ? 'null' : 'set', "\n";
echo 'removed_first=', ($orphan->firstChild === null) ? 'null' : $orphan->firstChild->nodeValue, "\n";
