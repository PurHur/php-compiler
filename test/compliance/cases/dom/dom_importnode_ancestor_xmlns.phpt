--TEST--
DOMDocument::importNode() materializes ancestor xmlns on saveXML (#21482, ext/dom/document.c)
--FILE--
<?php
$src = new DOMDocument();
$src->loadXML('<a xmlns:p="urn:p" xmlns:q="urn:q"><p:b p:x="1"><q:c/></p:b></a>');
$dst = new DOMDocument();
$dst->loadXML('<root/>');
$imp = $dst->importNode($src->documentElement->firstChild, true);
echo $dst->saveXML($imp), "\n";

$src2 = new DOMDocument();
$src2->loadXML('<a xmlns="urn:d"><b/></a>');
$dst2 = new DOMDocument();
$dst2->loadXML('<root/>');
echo $dst2->saveXML($dst2->importNode($src2->documentElement->firstChild, true)), "\n";

$src3 = new DOMDocument();
$src3->loadXML('<a xmlns:p="urn:p"><p:b><q:c xmlns:q="urn:q"/></p:b></a>');
$dst3 = new DOMDocument();
$dst3->loadXML('<root/>');
echo $dst3->saveXML($dst3->importNode($src3->documentElement->firstChild, true)), "\n";
?>
--EXPECT--
<p:b xmlns:p="urn:p" xmlns:q="urn:q" p:x="1"><q:c/></p:b>
<b xmlns="urn:d"/>
<p:b xmlns:p="urn:p"><q:c xmlns:q="urn:q"/></p:b>
