<?php
declare(strict_types=1);

/**
 * #33607 — AOT DOMNode::$nodeType must match Zend (php-src ext/dom/node.c).
 */
$d = new DOMDocument();
$e = $d->createElement('root');
$a = $d->createAttribute('k');
$a->value = 'v';
$e->setAttributeNode($a);
$t = $d->createTextNode('x');
$c = $d->createComment('y');
echo $d->nodeType, '|', $e->nodeType, '|', $a->nodeType, '|', $t->nodeType, '|', $c->nodeType, "\n";
