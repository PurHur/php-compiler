--TEST--
Dom\Implementation::createDocument / createDocumentType living return types (#20910)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#20910)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$impl = Dom\HTMLDocument::createEmpty()->implementation;
echo get_class($impl), "\n";

$doc = $impl->createDocument(null, 'root');
echo get_class($doc), "\n";
echo get_class($doc->documentElement), "\n";
echo $doc->xmlVersion, "\n";

$nsDoc = $impl->createDocument('http://example.com/ns', 'ex:root');
echo get_class($nsDoc->documentElement), "\n";
echo $nsDoc->documentElement->namespaceURI, "\n";

$dt = $impl->createDocumentType('html', '', '');
echo get_class($dt), "\n";
echo $dt->name, "\n";

$withDt = $impl->createDocument(null, 'html', $dt);
echo get_class($withDt), "\n";
echo get_class($withDt->doctype), "\n";

$html = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body></body></html>');
echo get_class($html->doctype), "\n";

$legacy = (new DOMImplementation())->createDocument(null, 'legacy');
echo get_class($legacy), "\n";
$legacyDt = (new DOMImplementation())->createDocumentType('html');
echo get_class($legacyDt), "\n";
--EXPECT--
Dom\Implementation
Dom\XMLDocument
Dom\Element
1.0
Dom\Element
http://example.com/ns
Dom\DocumentType
html
Dom\XMLDocument
Dom\DocumentType
Dom\DocumentType
DOMDocument
DOMDocumentType
