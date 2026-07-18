<?php
declare(strict_types=1);

// #20676 — AOT DOMDocument::createAttribute + DOMElement::setAttributeNode

$d = new DOMDocument();
$a = $d->createAttribute('id');
echo $a->name, "\n";
$a->value = 'foo';
$e = $d->createElement('x');
$d->appendChild($e);
echo (null === $e->setAttributeNode($a) ? 'NULL' : 'prev'), "\n";
echo $e->getAttribute('id'), "\n";
$node = $e->getAttributeNode('id');
echo ($node instanceof DOMAttr ? $node->value : 'null'), "\n";
