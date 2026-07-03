--TEST--
DOMDocument libxml parser properties with Zend defaults (#14368, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
echo property_exists($doc, 'resolveExternals') ? 'resolveExternals ' : 'missing ';
echo property_exists($doc, 'preserveWhiteSpace') ? 'preserveWhiteSpace ' : 'missing ';
echo $doc->validateOnParse ? 'vop-true ' : 'vop-false ';
echo $doc->preserveWhiteSpace ? 'pws-true ' : 'pws-false ';
echo $doc->strictErrorChecking ? 'sec-true ' : 'sec-false ';
$doc->validateOnParse = true;
echo $doc->validateOnParse ? "roundtrip\n" : "fail\n";
?>
--EXPECT--
resolveExternals preserveWhiteSpace vop-false pws-true sec-true roundtrip
