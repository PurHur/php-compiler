--TEST--
dom DOMDocument::loadXML() ATTLIST default/#FIXED attributes (#21456, ext/dom/document.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA "def">]><r/>');
echo 'default=', $doc->documentElement->getAttribute('x'), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA #FIXED "fix">]><r/>');
echo 'fixed=', $doc2->documentElement->getAttribute('x'), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA "def" y CDATA "Y">]><r x="keep"/>');
echo 'keep=', $doc3->documentElement->getAttribute('x'), ' y=', $doc3->documentElement->getAttribute('y'), "\n";

$doc4 = new DOMDocument();
$doc4->loadXML('<!DOCTYPE r [<!ATTLIST child z CDATA "Z">]><r><child/></r>');
echo 'nested=', $doc4->getElementsByTagName('child')->item(0)->getAttribute('z'), "\n";

$doc5 = new DOMDocument();
$doc5->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA #IMPLIED>]><r/>');
echo 'implied_has=', $doc5->documentElement->hasAttribute('x') ? 'y' : 'n', "\n";

$doc6 = new DOMDocument();
$doc6->loadXML('<!DOCTYPE r [<!ATTLIST r x (a|b) "a">]><r/>');
echo 'enum=', $doc6->documentElement->getAttribute('x'), "\n";

$doc7 = new DOMDocument();
$doc7->loadXML('<!DOCTYPE r [<!ATTLIST child id ID #IMPLIED>]><r><child id="t"/></r>');
echo 'explicit_id=', null !== $doc7->getElementById('t') ? 'y' : 'n', "\n";
--EXPECT--
default=def
fixed=fix
keep=keep y=Y
nested=Z
implied_has=n
enum=a
explicit_id=y
