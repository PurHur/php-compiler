<?php
declare(strict_types=1);

/**
 * #33598 — AOT setAttributeNode must set Attr::$ownerElement (php-src element.c).
 *
 * Critical: appendChild() returns DOMNode, so the call is typed as
 * domnode::setattributenode — must use the same user-script proxy as DOMElement.
 */
$d = new DOMDocument();
$e = $d->appendChild($d->createElement('root'));
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
echo $a->ownerElement->tagName, "\n";
echo $a->value, "\n";
