<?php

declare(strict_types=1);

/**
 * #33230 — AOT toggleAttribute must pin/unpin held NamedNodeMap (peer #33143).
 * php-src ext/dom/element.c dom_element_toggle_attribute / xmlUnsetProp / xmlSetProp.
 *
 * Requires PHP_COMPILER_PROFILE=8.3+ (toggleAttribute is PHP 8.3+).
 */
$d = new DOMDocument();
$el = $d->createElement('r');
$el->setAttribute('a', '1');
$el->setAttribute('b', '2');
$map = $el->attributes;
echo 'before=', $map->length, ' ', $map->item(0)->name, "\n";
$still = $el->toggleAttribute('a');
echo 'toggle_ret=', $still ? '1' : '0', "\n";
echo 'after=', $map->length, ' ', ($map->item(0) ? $map->item(0)->name : 'null'), "\n";
echo 'has_a=', $el->hasAttribute('a') ? '1' : '0', ' get_a=', var_export($el->getAttribute('a'), true), "\n";
echo 'has_b=', $el->hasAttribute('b') ? '1' : '0', "\n";

$el->toggleAttribute('c', true);
echo 'force_true_has=', $el->hasAttribute('c') ? '1' : '0', ' map=', $el->attributes->length, "\n";
$el->toggleAttribute('c', false);
echo 'force_false_has=', $el->hasAttribute('c') ? '1' : '0', ' map=', $el->attributes->length, "\n";
