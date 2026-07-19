<?php
/**
 * Repro #21000 — DOMDocumentType::$internalSubset / Dom\DocumentType::$internalSubset.
 */
error_reporting(E_ALL);

function dumpSubset(?string $s): string
{
    if (null === $s) {
        return 'NULL';
    }

    return 'len='.strlen($s).' hex='.bin2hex($s);
}

$d = new DOMDocument();
$d->loadXML('<!DOCTYPE r [<!ELEMENT r EMPTY>]><r/>');
echo 'legacy_subset:', dumpSubset($d->doctype->internalSubset), "\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r><r/>');
echo 'legacy_none:', dumpSubset($d2->doctype->internalSubset), "\n";

$d3 = new DOMDocument();
$d3->loadXML('<!DOCTYPE r PUBLIC "pub" "sys" [<!ENTITY foo "bar"><!ELEMENT r (#PCDATA)>]><r>&foo;</r>');
echo 'legacy_public:', dumpSubset($d3->doctype->internalSubset), "\n";
echo 'legacy_public_entities:', $d3->doctype->entities->length, "\n";
echo 'legacy_public_text:', json_encode($d3->documentElement->textContent), "\n";

$impl = new DOMImplementation();
$dt = $impl->createDocumentType('r', '', '');
echo 'create:', dumpSubset($dt->internalSubset), "\n";

if (class_exists('Dom\\XMLDocument')) {
    $xd = Dom\XMLDocument::createFromString('<!DOCTYPE r [<!ELEMENT r EMPTY>]><r/>');
    echo 'living_subset:', dumpSubset($xd->doctype->internalSubset), "\n";
    echo 'living_class:', get_class($xd->doctype), "\n";
}
