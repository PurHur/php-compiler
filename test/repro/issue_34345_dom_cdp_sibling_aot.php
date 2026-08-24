<?php
// #34345 — AOT compareDocumentPosition sibling walk via nextSibling (ext/dom/node.c)
declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<r><a/><b/></r>');
$a = $d->documentElement->firstChild;
$b = $a->nextSibling;
echo $a->compareDocumentPosition($b), '|', $b->compareDocumentPosition($a), "\n";

$doc = new DOMDocument();
$root = $doc->createElement('root');
$parent = $doc->createElement('parent');
$child = $doc->createElement('child');
$sibling = $doc->createElement('sibling');
$doc->appendChild($root);
$root->append($parent, $sibling);
$parent->appendChild($child);
echo $parent->compareDocumentPosition($child), "\n";
echo $child->compareDocumentPosition($parent), "\n";
echo $parent->compareDocumentPosition($sibling), "\n";
