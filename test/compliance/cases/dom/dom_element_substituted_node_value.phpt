--TEST--
Dom\Element::$substitutedNodeValue get/set (#21034)
--SKIPIF--
<?php
if (!class_exists('Dom\\XMLDocument')) {
    die('skip Dom\\XMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21034)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$doc = Dom\XMLDocument::createFromString('<r><e>hi &amp; there</e></r>');
$el = $doc->documentElement->firstElementChild;
echo 'isset=', isset($el->substitutedNodeValue) ? 'yes' : 'no', "\n";
echo 'get=', var_export($el->substitutedNodeValue, true), "\n";
echo 'nodeValue=', var_export($el->nodeValue, true), "\n";

$el->substitutedNodeValue = 'x &amp; y';
echo 'afterAmp text=', var_export($el->textContent, true), "\n";
echo 'afterAmp subst=', var_export($el->substitutedNodeValue, true), "\n";

$dom = Dom\XMLDocument::createFromString('<root/>');
$root = $dom->documentElement;
$root->substitutedNodeValue = '1';
var_dump($root->substitutedNodeValue);
var_dump($root->nodeValue);
echo rtrim($dom->saveXml()), "\n";

$root->substitutedNodeValue = '<>';
var_dump($root->substitutedNodeValue);
var_dump($root->nodeValue);
echo rtrim($dom->saveXml()), "\n";

$root->substitutedNodeValue = '';
var_dump($root->substitutedNodeValue);
var_dump($root->nodeValue);
echo rtrim($dom->saveXml()), "\n";

$xml = <<<'XML'
<!DOCTYPE r [
  <!ENTITY foo "BAR">
]>
<r><e>hi &foo; there</e></r>
XML;
$withEnt = Dom\XMLDocument::createFromString($xml);
$entEl = $withEnt->documentElement->firstElementChild;
echo 'entitySubst=', var_export($entEl->substitutedNodeValue, true), "\n";
?>
--EXPECTF--
isset=yes
get='hi & there'
nodeValue=NULL
afterAmp text='x & y'
afterAmp subst='x & y'
string(1) "1"
NULL
<?xml version="1.0"%s
<root>1</root>
string(2) "<>"
NULL
<?xml version="1.0"%s
<root>&lt;&gt;</root>
string(0) ""
NULL
<?xml version="1.0"%s
<root/>
entitySubst='hi BAR there'
