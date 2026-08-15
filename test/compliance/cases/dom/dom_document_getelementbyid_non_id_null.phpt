--TEST--
DOMDocument::getElementById() plain id (not ID-typed) returns null (#31367)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a></r>');
var_export($d->getElementById('x'));
echo "\n";
$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r [<!ATTLIST a id ID #IMPLIED>]><r><a id="x">1</a></r>');
echo $d2->getElementById('x')->textContent, "\n";
?>
--EXPECT--
NULL
1
