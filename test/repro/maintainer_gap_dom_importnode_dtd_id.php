<?php
/**
 * importNode must preserve libxml XML_ATTRIBUTE_ID from DTD ATTLIST so
 * Attr::isId() and getElementById() work on the target (php-src xmlCopyProp).
 * setIdAttribute() must NOT survive import — covered separately.
 */
$src = new DOMDocument();
$src->loadXML('<!DOCTYPE x [<!ATTLIST c id ID #IMPLIED>]><r><c id="t">x</c></r>');
$el = $src->documentElement->firstChild;
echo 'src_isId=', var_export($el->getAttributeNode('id')->isId(), true), "\n";

$dst = new DOMDocument();
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($el, true);
echo 'imported_isId=', var_export($n->getAttributeNode('id')->isId(), true), "\n";
$dst->documentElement->appendChild($n);
$found = $dst->getElementById('t');
echo 'getElementById=', null !== $found ? $found->tagName : 'null', "\n";
