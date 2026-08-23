<?php
// #34050 — AOT setIdAttribute on firstChild must register that element's id.
// Global DomUserScriptAttributeCacheLlvm is keyed by attr name only; last id= won.
// php-src: ext/dom/element.c PHP_METHOD(DOMElement, setIdAttribute) → xmlAddID
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a><b id="y">2</b></r>');
$e = $d->documentElement->firstChild;
echo 'attr=', $e->getAttribute('id'), "\n";
$e->setIdAttribute('id', true);
$hit = $d->getElementById('x');
echo $hit === null ? "x=null\n" : ('x='.$hit->nodeName."\n");
$miss = $d->getElementById('y');
echo $miss === null ? "y=null\n" : ('y='.$miss->nodeName."\n");

// Custom id-bearing attr name (not literally "id")
$d2 = new DOMDocument();
$d2->loadXML('<r><a xid="1"/><b xid="2"/></r>');
$a = $d2->documentElement->firstChild;
$a->setIdAttribute('xid', true);
$got = $d2->getElementById('1');
echo $got === null ? "xid=null\n" : ('xid='.$got->nodeName."\n");

// xmlAddID first-wins on duplicate setIdAttribute
$d3 = new DOMDocument();
$d3->loadXML('<r><a id="z"/><b id="z"/></r>');
$a3 = $d3->documentElement->firstChild;
$b3 = $a3->nextSibling;
$a3->setIdAttribute('id', true);
$b3->setIdAttribute('id', true);
$dup = $d3->getElementById('z');
echo $dup === null ? "dup=null\n" : ('dup='.$dup->nodeName."\n");
