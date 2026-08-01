<?php
declare(strict_types=1);

// #26065 — Dom\Element/HTMLElement getAttribute* Reflection return types (PROFILE=8.4)
$d = Dom\HTMLDocument::createEmpty();
$el = $d->createElement('div');
foreach (['getAttribute', 'getAttributeNS', 'getAttributeNode', 'getAttributeNodeNS'] as $m) {
    $rm = new ReflectionMethod($el, $m);
    $t = $rm->getReturnType();
    echo $m, ' ret=', $t ? $t->__toString() : '(none)',
        ' has=', $rm->hasReturnType() ? '1' : '0',
        ' declaring=', $rm->getDeclaringClass()->getName(),
        PHP_EOL;
}
// Class-string Reflection on Dom\Element (not only HTMLElement instance).
foreach (['getAttribute', 'getAttributeNode'] as $m) {
    $rm = new ReflectionMethod(Dom\Element::class, $m);
    $t = $rm->getReturnType();
    echo 'Element::', $m, ' ret=', $t ? $t->__toString() : '(none)', PHP_EOL;
}
