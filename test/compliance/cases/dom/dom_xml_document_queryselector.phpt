--TEST--
Dom\XMLDocument querySelector/querySelectorAll via Dom\Document ParentNode (#29453)
--FILE--
<?php
use Dom\XMLDocument;
use Dom\HTMLDocument;

$xml = XMLDocument::createFromString('<r><a id="x"><b class="c">t</b></a><c/></r>');
echo 'xml_has=', method_exists($xml, 'querySelector') ? '1' : '0', "\n";
echo 'xml_qs=', $xml->querySelector('a')?->localName ?? 'null', "\n";
echo 'xml_qsa=', $xml->querySelectorAll('b')->length, "\n";
echo 'xml_list=', $xml->querySelectorAll('a, c')->length, "\n";

$rx = new ReflectionMethod(XMLDocument::class, 'querySelector');
echo 'xml_decl=', $rx->getDeclaringClass()->getName(), "\n";

$html = HTMLDocument::createFromString('<!DOCTYPE html><html><body><p id="p">x</p></body></html>');
echo 'html_qs=', $html->querySelector('#p')?->tagName ?? 'null', "\n";
$rh = new ReflectionMethod(HTMLDocument::class, 'querySelector');
echo 'html_decl=', $rh->getDeclaringClass()->getName(), "\n";

try {
    $xml->querySelector();
} catch (ArgumentCountError $e) {
    echo 'arity=', $e->getMessage(), "\n";
}
try {
    $xml->querySelector([]);
} catch (TypeError $e) {
    echo 'type=', $e->getMessage(), "\n";
}
--EXPECT--
xml_has=1
xml_qs=a
xml_qsa=1
xml_list=2
xml_decl=Dom\Document
html_qs=P
html_decl=Dom\Document
arity=Dom\Document::querySelector() expects exactly 1 argument, 0 given
type=Dom\Document::querySelector(): Argument #1 ($selectors) must be of type string, array given
