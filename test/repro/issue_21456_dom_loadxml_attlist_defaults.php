<?php
/** Repro #21456 — loadXML ATTLIST default/#FIXED attributes (ext/dom/document.c). */
error_reporting(E_ALL);

$doc = new DOMDocument();
$doc->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA "def">]><r/>');
echo 'default=', $doc->documentElement->getAttribute('x'), "\n";

$doc2 = new DOMDocument();
$doc2->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA "def">]><r/>', LIBXML_DTDATTR);
echo 'with_flag=', $doc2->documentElement->getAttribute('x'), "\n";

$doc3 = new DOMDocument();
$doc3->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA #FIXED "fix">]><r/>');
echo 'fixed=', $doc3->documentElement->getAttribute('x'), "\n";

$doc4 = new DOMDocument();
$doc4->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA "def" y CDATA "Y">]><r x="keep"/>');
echo 'keep=', $doc4->documentElement->getAttribute('x'), ' y=', $doc4->documentElement->getAttribute('y'), "\n";

$doc5 = new DOMDocument();
$doc5->loadXML('<!DOCTYPE r [<!ATTLIST child z CDATA "Z">]><r><child/></r>');
echo 'nested=', $doc5->getElementsByTagName('child')->item(0)->getAttribute('z'), "\n";

$doc6 = new DOMDocument();
$doc6->loadXML('<!DOCTYPE r [<!ATTLIST r x CDATA #IMPLIED>]><r/>');
echo 'implied_has=', $doc6->documentElement->hasAttribute('x') ? 'y' : 'n', "\n";

$doc7 = new DOMDocument();
$doc7->loadXML('<!DOCTYPE r [<!ATTLIST r x (a|b) "a">]><r/>');
echo 'enum=', $doc7->documentElement->getAttribute('x'), "\n";
