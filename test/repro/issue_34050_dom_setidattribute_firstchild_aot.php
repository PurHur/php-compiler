<?php
// #34050 — AOT setIdAttribute on firstChild must use that element's attr value
// (global DomUserScriptAttributeCacheLlvm is name-keyed; last id= used to win).
// php-src: ext/dom/element.c PHP_METHOD(DOMElement, setIdAttribute) → xmlAddID
$d = new DOMDocument();
$d->loadXML('<r><a id="x" foo="fx">1</a><b id="y" foo="fy">2</b></r>');
$e = $d->documentElement->firstChild;
$e->setIdAttribute('id', true);
$hit = $d->getElementById('x');
if (null === $hit) {
    echo "x=null\n";
} else {
    echo 'x=', $hit->nodeName, "\n";
}
$miss = $d->getElementById('y');
if (null === $miss) {
    echo "y=null\n";
} else {
    echo 'y=', $miss->nodeName, "\n";
}
echo 'attr=', $e->getAttribute('id'), "\n";
echo 'foo=', $e->getAttribute('foo'), "\n";

// Custom id-attr name on firstChild
$d2 = new DOMDocument();
$d2->loadXML('<r><a mid="m1"/><b mid="m2"/></r>');
$a2 = $d2->documentElement->firstChild;
$a2->setIdAttribute('mid', true);
$m = $d2->getElementById('m1');
if (null === $m) {
    echo "m1=null\n";
} else {
    echo 'm1=', $m->nodeName, "\n";
}

// Duplicate setIdAttribute: xmlAddID first-wins (#25275)
$d3 = new DOMDocument();
$d3->loadXML('<r><a id="z"/><b id="z"/></r>');
$a3 = $d3->documentElement->firstChild;
$b3 = $a3->nextSibling;
$a3->setIdAttribute('id', true);
$b3->setIdAttribute('id', true);
echo $d3->getElementById('z')->nodeName, "\n";
