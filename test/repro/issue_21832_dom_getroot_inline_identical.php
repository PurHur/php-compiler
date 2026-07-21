<?php
/**
 * #21832 — inline getRootNode() === $doc false on AOT while isSameNode true.
 */
$doc = new DOMDocument();
$root = $doc->createElement('root');
$child = $doc->createElement('child');
$leaf = $doc->createElement('leaf');
$doc->appendChild($root);
$root->appendChild($child);
$child->appendChild($leaf);
echo (int) $doc->isSameNode($leaf->getRootNode()), "\n";
echo (int) ($leaf->getRootNode() === $doc), "\n";
