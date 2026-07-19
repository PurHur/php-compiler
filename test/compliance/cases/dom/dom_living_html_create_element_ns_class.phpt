--TEST--
Dom\HTMLDocument createElement/createElementNS class follows HTML namespace (#21030)
--SKIPIF--
<?php
if (!class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\HTMLDocument requires PHP_COMPILER_PROFILE=8.4 (#21030)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$htmlNs = 'http://www.w3.org/1999/xhtml';
$doc = Dom\HTMLDocument::createFromString('<!doctype html><html><body></body></html>');

$div = $doc->createElement('DIV');
echo 'createElement=', get_class($div), ' name=', $div->nodeName, ' ns=', $div->namespaceURI, "\n";

$html = $doc->createElementNS($htmlNs, 'span');
echo 'htmlns=', get_class($html), "\n";

$svg = $doc->createElementNS('http://www.w3.org/2000/svg', 'svg');
echo 'svg=', get_class($svg), ' html=', ($svg instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$custom = $doc->createElementNS('urn:x', 'x:y');
echo 'custom=', get_class($custom), ' html=', ($custom instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$nullNs = $doc->createElementNS(null, 'orphan');
echo 'nullns=', get_class($nullNs), ' html=', ($nullNs instanceof Dom\HTMLElement) ? 'yes' : 'no', "\n";

$xd = Dom\XMLDocument::createFromString('<root/>');
echo 'xml_htmlns=', get_class($xd->createElementNS($htmlNs, 'div')), "\n";
?>
--EXPECT--
createElement=Dom\HTMLElement name=div ns=http://www.w3.org/1999/xhtml
htmlns=Dom\HTMLElement
svg=Dom\Element html=no
custom=Dom\Element html=no
nullns=Dom\Element html=no
xml_htmlns=Dom\Element
