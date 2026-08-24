<?php

/**
 * AOT: setAttribute + getAttribute + replaceChild then chained firstChild->nodeName.
 *
 * php-src ext/dom/node.c dom_node_replace_child; ext/dom/element.c getAttribute.
 */
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->setAttribute('id', 'main');
echo 'getAttribute.id='.$root->getAttribute('id')."\n";

$child1 = $doc->createElement('child1');
$child2 = $doc->createElement('child2');
$child3 = $doc->createElement('child3');
$root->appendChild($child1);
$root->appendChild($child2);
$root->replaceChild($child3, $child1);
echo 'chained='.$root->firstChild->nodeName."\n";
$fc = $root->firstChild;
echo 'assigned='.$fc->nodeName."\n";
echo "DONE\n";
