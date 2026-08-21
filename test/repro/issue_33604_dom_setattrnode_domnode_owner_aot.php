<?php
declare(strict_types=1);

/**
 * #33604 — AOT setAttributeNode on DOMNode-typed receiver (appendChild return) must set ownerElement.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('root'));
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
echo $a->ownerElement->tagName, "\n";
echo $a->value, "\n";
