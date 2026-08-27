<?php

declare(strict_types=1);

/**
 * #35261 — AOT setAttribute on DOMNode-typed appendChild return must stick (peer #33604).
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('e'));
$e->setAttribute('a', '1');
$e->setAttribute('b', '2');
echo 'xml=', trim($d->saveXML($e));
echo ' get=', $e->getAttribute('a');
echo ' has=', $e->hasAttribute('a') ? '1' : '0';
$n = $e->getAttributeNode('a');
echo ' node=', ($n instanceof DOMAttr ? $n->name.'='.$n->value : 'MISS');
echo "\n";
