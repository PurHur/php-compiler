<?php
$doc = new DOMDocument();
$root = $doc->createElement('root');
$parent = $doc->createElement('parent');
$child = $doc->createElement('child');
$sibling = $doc->createElement('sibling');
$doc->appendChild($root);
$root->append($parent);
$root->append($sibling);
$parent->append($child);
echo (int) $root->contains($child), "\n";
echo (int) $parent->contains($child), "\n";
echo (int) $child->contains($root), "\n";
echo (int) $root->contains($sibling), "\n";
echo (int) $root->contains($root), "\n";
echo (int) $root->contains(null), "\n";
