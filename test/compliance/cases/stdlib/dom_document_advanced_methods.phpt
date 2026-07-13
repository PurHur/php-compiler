--TEST--
DOMDocument::normalizeDocument() and validation method surface (#14370, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
echo method_exists($doc, 'normalizeDocument') ? 'normalize ' : 'missing ';
echo method_exists($doc, 'schemaValidate') ? 'schema ' : 'missing ';
echo method_exists($doc, 'relaxNGValidate') ? 'relaxng ' : 'missing ';
echo method_exists($doc, 'schemaValidateSource') ? 'schema-source ' : 'missing ';
echo method_exists($doc, 'relaxNGValidateSource') ? 'relaxng-source ' : 'missing ';
$doc->loadXML('<root/>');
$doc->normalizeDocument();
echo "ok\n";
?>
--EXPECT--
normalize schema relaxng schema-source relaxng-source ok
