<?php
// #33957 — AOT setIdAttribute on a later sibling must register that element's id.
// php-src: ext/dom/element.c PHP_METHOD(DOMElement, setIdAttribute) → xmlAddID
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a><b id="y">2</b></r>');
$e = $d->getElementsByTagName('b')->item(0);
$e->setIdAttribute('id', true);
$hit = $d->getElementById('y');
if ($hit === null) {
    echo "y=null\n";
} else {
    echo 'y=', $hit->nodeName, "\n";
}
$miss = $d->getElementById('x');
if ($miss === null) {
    echo "x=null\n";
} else {
    echo 'x=', $miss->nodeName, "\n";
}
