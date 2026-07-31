<?php

declare(strict_types=1);

/**
 * Repro: Dom\Attr::rename() (#21083) — php-src @implementation-alias Dom\Element::rename.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_dom_attr_rename.php
 */

$d = Dom\XMLDocument::createFromString('<r a="1" c="3"/>');
$el = $d->documentElement;
$attr = $el->getAttributeNode('a');
echo 'exists=', method_exists($attr, 'rename') ? 'yes' : 'no', "\n";
echo 'class=', get_class($attr), "\n";

$attr->rename(null, 'b');
echo 'ren1:name=', $attr->name, ',nodeName=', $attr->nodeName, ',local=', $attr->localName, "\n";
echo 'ren1:has_a=', $el->hasAttribute('a') ? '1' : '0', ',has_b=', $el->hasAttribute('b') ? '1' : '0',
    ',val=', $el->getAttribute('b'), "\n";

$attr->rename('urn:x', 'x:b');
echo 'ren2:name=', $attr->name, ',nodeName=', $attr->nodeName, ',ns=', $attr->namespaceURI,
    ',prefix=', $attr->prefix, "\n";
// living Dom\Attr::$name is QName (#26024) — name === nodeName after rename
echo 'ren2:has_b=', $el->hasAttribute('b') ? '1' : '0',
    ',ns_val=', $el->getAttributeNS('urn:x', 'b'), "\n";

try {
    $attr->rename(null, 'c');
    echo "dup_ok\n";
} catch (DOMException $e) {
    echo 'dup:', (str_contains($e->getMessage(), 'already exists') ? 'exists' : $e->getMessage()),
        ',code=', $e->getCode(), "\n";
}

$orphan = $d->createAttribute('z');
$orphan->value = '9';
$orphan->rename(null, 'w');
echo 'orphan:name=', $orphan->name, ',nodeName=', $orphan->nodeName, ',val=', $orphan->value, "\n";
