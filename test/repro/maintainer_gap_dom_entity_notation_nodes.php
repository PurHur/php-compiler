<?php

declare(strict_types=1);

// DTD entity/notation node types after loadXML (#6320, php-src ext/dom/php_dom.c).
$xml = '<!DOCTYPE doc [<!ENTITY ent "val"><!NOTATION n PUBLIC "n" "u">]><doc>&ent;</doc>';
$doc = new DOMDocument();
if (!$doc->loadXML($xml)) {
    echo "fail: loadXML\n";
    exit(1);
}

if (!class_exists('DOMEntity') || !class_exists('DOMNotation')) {
    echo "fail: missing DOMEntity/DOMNotation classes\n";
    exit(1);
}

$ref = $doc->documentElement->firstChild;
if (!$ref instanceof DOMEntityReference) {
    echo 'fail: firstChild is ', get_debug_type($ref), "\n";
    exit(1);
}
if ('ent' !== $ref->nodeName) {
    echo 'fail: entity ref name ', $ref->nodeName, "\n";
    exit(1);
}
if ('val' !== $ref->textContent) {
    echo 'fail: entity ref textContent ', var_export($ref->textContent, true), "\n";
    exit(1);
}

$doctype = $doc->doctype;
if (null === $doctype) {
    echo "fail: missing doctype\n";
    exit(1);
}
if (1 !== $doctype->entities->length) {
    echo 'fail: entities length ', $doctype->entities->length, "\n";
    exit(1);
}
$entity = $doctype->entities->item(0);
if (!$entity instanceof DOMEntity) {
    echo 'fail: entity decl is ', get_debug_type($entity), "\n";
    exit(1);
}
if ('ent' !== $entity->nodeName || 17 !== $entity->nodeType) {
    echo 'fail: entity decl name/type ', $entity->nodeName, ' ', $entity->nodeType, "\n";
    exit(1);
}

if (1 !== $doctype->notations->length) {
    echo 'fail: notations length ', $doctype->notations->length, "\n";
    exit(1);
}
$notation = $doctype->notations->item(0);
if (!$notation instanceof DOMNotation) {
    echo 'fail: notation is ', get_debug_type($notation), "\n";
    exit(1);
}
if ('n' !== $notation->nodeName || 12 !== $notation->nodeType) {
    echo 'fail: notation name/type ', $notation->nodeName, ' ', $notation->nodeType, "\n";
    exit(1);
}
if ('n' !== $notation->publicId || 'u' !== $notation->systemId) {
    echo 'fail: notation ids ', var_export($notation->publicId, true), ' ', var_export($notation->systemId, true), "\n";
    exit(1);
}

echo "ok\n";
