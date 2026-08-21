<?php
declare(strict_types=1);

/**
 * #33598 — AOT setAttributeNode must set Attr::$ownerElement (php-src element.c).
 */
$d = new DOMDocument();
$e = $d->createElement('root');
$d->appendChild($e);
$a = $d->createAttribute('id');
$a->value = 'x';
$e->setAttributeNode($a);
$owner = $a->ownerElement;
if (null === $owner) {
    echo "null\n";
} else {
    echo $owner->tagName, "\n";
}
