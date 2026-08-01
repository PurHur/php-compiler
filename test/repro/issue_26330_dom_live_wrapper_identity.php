<?php
/**
 * Repro #26330 — DOMElement::$attributes / DOMNode::$childNodes object identity.
 * Zend returns a distinct wrapper per property read; captured wrappers stay live.
 */
$dom = new DOMDocument();
$dom->loadXML('<root><a/></root>');
$root = $dom->documentElement;

$a1 = $root->attributes;
$a2 = $root->attributes;
echo 'attr_same=', ($a1 === $a2) ? 'yes' : 'no', "\n";

$c1 = $root->childNodes;
$c2 = $root->childNodes;
echo 'child_same=', ($c1 === $c2) ? 'yes' : 'no', "\n";

$root->setAttribute('id', 'x');
echo 'attr_len=', $a1->length, "\n";

$before = $c1->length;
$root->appendChild($dom->createElement('b'));
echo 'child_len=', $c1->length, ' grew=', ($c1->length > $before) ? 'yes' : 'no', "\n";

$g1 = $dom->getElementsByTagName('a');
$g2 = $dom->getElementsByTagName('a');
echo 'get_same=', ($g1 === $g2) ? 'yes' : 'no', "\n";
