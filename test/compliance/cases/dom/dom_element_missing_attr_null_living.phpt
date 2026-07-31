--TEST--
Dom\Element missing getAttribute/NS/Node return null; legacy ''/false (#26062, ext/dom/element.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div id="d" title="t"></div></body></html>',
    LIBXML_NOERROR
);
$el = $d->getElementById('d');
echo 'living getAttribute=';
var_export($el->getAttribute('nope'));
echo "\n";
echo 'living getAttributeNS=';
var_export($el->getAttributeNS(null, 'nope'));
echo "\n";
echo 'living getAttributeNode=';
var_export($el->getAttributeNode('nope'));
echo "\n";
echo 'living present=';
var_export($el->getAttribute('title'));
echo "\n";

$doc = new DOMDocument();
$doc->loadHTML('<div id="x"></div>', LIBXML_NOERROR);
$le = $doc->getElementById('x');
echo 'legacy getAttribute=';
var_export($le->getAttribute('nope'));
echo "\n";
echo 'legacy getAttributeNS=';
var_export($le->getAttributeNS(null, 'nope'));
echo "\n";
echo 'legacy getAttributeNode=';
var_export($le->getAttributeNode('nope'));
echo "\n";
?>
--EXPECT--
living getAttribute=NULL
living getAttributeNS=NULL
living getAttributeNode=NULL
living present='t'
legacy getAttribute=''
legacy getAttributeNS=''
legacy getAttributeNode=false
