<?php
/**
 * DOMNode::insertBefore($node, null) ≡ append — must preserve prior siblings (#26458).
 * Literal null (not only a null variable) must match Zend / php-src ext/dom/node.c.
 */
$d = new DOMDocument();
$r = $d->appendChild($d->createElement('root'));
$a = $d->createElement('a');
$b = $d->createElement('b');
$r->appendChild($a);
$ret = $r->insertBefore($b, null);
echo $r->childNodes->length, ' ', $r->C14N(), PHP_EOL;
echo $ret->nodeName, PHP_EOL;
