--TEST--
AOT: Document ChildNode::before/after + insertBefore comment — saveXML + childNodes (#34160; ext/dom/php_dom.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
$r = $d->documentElement;
$r->before($d->createComment('x'));
echo $d->saveXML();
echo 'len=', $d->childNodes->length, "\n";
echo 'item0=', $d->childNodes->item(0)->nodeName, "\n";
echo 'item1=', $d->childNodes->item(1)->nodeName, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r/>');
$d2->documentElement->after($d2->createComment('y'));
echo $d2->saveXML();
echo 'len2=', $d2->childNodes->length, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r/>');
$d3->insertBefore($d3->createComment('z'), $d3->documentElement);
echo $d3->saveXML();
echo 'len3=', $d3->childNodes->length, "\n";
--EXPECT--
<?xml version="1.0"?>
<!--x-->
<r/>
len=2
item0=#comment
item1=r
<?xml version="1.0"?>
<r/>
<!--y-->
len2=2
<?xml version="1.0"?>
<!--z-->
<r/>
len3=2
