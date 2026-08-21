<?php

declare(strict_types=1);

/**
 * #33143 — AOT removeAttribute must clear Attr cache + held NamedNodeMap pins.
 * php-src ext/dom/element.c xmlUnsetProp / namednodemap.c.
 */
$d = new DOMDocument();
$el = $d->createElement('r');
$el->setAttribute('a', '1');
$el->setAttribute('b', '2');
$map = $el->attributes;
echo 'before=', $map->length, ' ', $map->item(0)->name, "\n";
$el->removeAttribute('a');
echo 'after=', $map->length, ' ', ($map->item(0) ? $map->item(0)->name : 'null'), "\n";
echo 'has=', $el->hasAttribute('a') ? '1' : '0', ' get=', var_export($el->getAttribute('a'), true), "\n";
echo 'named_b=', ($map->getNamedItem('b') ? $map->getNamedItem('b')->value : 'null'), "\n";

$d2 = new DOMDocument();
$el2 = $d2->createElement('r');
$d2->appendChild($el2);
$a1 = $d2->createAttribute('x');
$a1->value = 'y';
$el2->setAttributeNode($a1);
$a2 = $d2->createAttribute('x');
$a2->value = 'z';
$el2->setAttributeNode($a2);
echo 'replace_len=', $el2->attributes->length, ' val=', $el2->getAttribute('x'), "\n";
