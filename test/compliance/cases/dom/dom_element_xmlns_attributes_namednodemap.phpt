--TEST--
DOMElement attributes NamedNodeMap excludes xmlns*; get/hasAttribute see nsDef (#19718)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r xmlns="urn:d" xmlns:p="urn:p" a="1"/>');
$el = $d->documentElement;
echo 'len=', $el->attributes->length, "\n";
for ($i = 0; $i < $el->attributes->length; $i++) {
    $a = $el->attributes->item($i);
    echo $a->name, '=', $a->value, "\n";
}
echo 'has_xmlns=', (int) $el->hasAttribute('xmlns'),
    ' get=', var_export($el->getAttribute('xmlns'), true), "\n";
echo 'has_xmlns_p=', (int) $el->hasAttribute('xmlns:p'),
    ' get=', var_export($el->getAttribute('xmlns:p'), true), "\n";

$created = new DOMDocument();
$root = $created->createElementNS('urn:d', 'r');
$created->appendChild($root);
echo 'create_has_xmlns=', (int) $root->hasAttribute('xmlns'),
    ' get=', var_export($root->getAttribute('xmlns'), true),
    ' len=', $root->attributes->length, "\n";
?>
--EXPECT--
len=1
a=1
has_xmlns=1 get='urn:d'
has_xmlns_p=1 get='urn:p'
create_has_xmlns=1 get='urn:d' len=0
