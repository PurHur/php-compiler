--TEST--
AOT: DOMDocument::getElementById() plain id (not ID-typed) returns null (#31367)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a></r>');
echo $d->getElementById('x') === null ? "null\n" : "found\n";
$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r [<!ATTLIST a id ID #IMPLIED>]><r><a id="x">1</a></r>');
echo $d2->getElementById('x') === null ? "dtd:null\n" : "dtd:ok\n";
?>
--EXPECT--
null
dtd:ok
