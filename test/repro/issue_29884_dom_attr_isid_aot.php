<?php
/**
 * #29884 — AOT DOMAttr::isId() must return bool (Zend/VM/JIT), not null.
 *
 * php-src: ext/dom/attr.c — dom_attr_is_id
 */
$d = new DOMDocument();
$e = $d->createElement('x');
$d->appendChild($e);
$e->setAttribute('id', 'foo');
$e->setIdAttribute('id', true);
var_export($e->getAttributeNode('id')->isId());
echo "\n";

$plain = $d->createAttribute('id2');
$plain->value = 'bar';
var_export($plain->isId());
echo "\n";
