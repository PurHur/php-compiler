--TEST--
dom DOMDocument::saveXML/saveHTML(DocumentFragment) child dump (#22453)
--FILE--
<?php
$doc = new DOMDocument();
$frag = $doc->createDocumentFragment();
$frag->appendChild($doc->createTextNode('hi'));
$frag->appendChild($doc->createElement('x'));
echo 'saveXML=', var_export($doc->saveXML($frag), true), "\n";
echo 'saveHTML=', var_export($doc->saveHTML($frag), true), "\n";

$empty = $doc->createDocumentFragment();
echo 'empty_xml=', var_export($doc->saveXML($empty), true), "\n";
echo 'empty_html=', var_export($doc->saveHTML($empty), true), "\n";

$doc->formatOutput = true;
$fmt = $doc->createDocumentFragment();
$fmt->appendChild($doc->createElement('a'));
$fmt->appendChild($doc->createElement('b'));
echo 'fmt_xml=', var_export($doc->saveXML($fmt), true), "\n";
echo 'fmt_html=', var_export($doc->saveHTML($fmt), true), "\n";
--EXPECT--
saveXML='hi<x/>'
saveHTML='hi<x></x>'
empty_xml=''
empty_html=''
fmt_xml='<a/>
<b/>
'
fmt_html='<a></a><b></b>'
