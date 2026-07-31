<?php
declare(strict_types=1);
// #25878 — AOT: compareDocumentPosition via createElement + multi-arg append
// (nextElementSibling is synced on multi-arg ParentNode::append under thin AOT)
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
echo (int) $parent->contains($child), "\n";
