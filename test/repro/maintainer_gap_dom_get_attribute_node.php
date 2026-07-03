<?php
declare(strict_types=1);
$doc = new DOMDocument();
$el = $doc->createElement('x');
$doc->appendChild($el);
$attr = $doc->createAttribute('id');
if (!$attr instanceof DOMAttr) {
    fwrite(STDERR, 'fail: createAttribute expected DOMAttr, got '.get_debug_type($attr)."\n");
    exit(1);
}
$attr->value = '1';
$el->setAttributeNode($attr);
$got = $el->getAttributeNode('id');
if (!$got instanceof DOMAttr) {
    fwrite(STDERR, 'fail: getAttributeNode expected DOMAttr, got '.get_debug_type($got)."\n");
    exit(1);
}
echo $got->name, "\n", $got->value, "\n";
$el->removeAttributeNode($got);
echo $el->hasAttribute('id') ? "has\n" : "no\n";
