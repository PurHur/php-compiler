--TEST--
DOMNode::C14N() emits processing instructions (#21659, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r><!--c--><?pi d?><x/></r>');
$el = $doc->documentElement;
echo $el->C14N(), "\n";
echo $el->C14N(false, true), "\n";
$pi = null;
foreach ($el->childNodes as $child) {
    if (XML_PI_NODE === $child->nodeType) {
        $pi = $child;
        break;
    }
}
echo $pi->C14N();
$empty = new DOMDocument();
$empty->loadXML('<r><?empty?></r>');
echo $empty->documentElement->firstChild->C14N();
$preamble = new DOMDocument();
$preamble->loadXML('<?xml version="1.0"?><!--docc--><?top?><r><?inner?></r><?after?>');
echo $preamble->C14N(), "\n";
echo $preamble->C14N(false, true), "\n";
?>
--EXPECT--
<r><?pi d?><x></x></r>
<r><!--c--><?pi d?><x></x></r>
<?pi d?>
<?empty?>
<?top?>
<r><?inner?></r>
<?after?>
<!--docc-->
<?top?>
<r><?inner?></r>
<?after?>
