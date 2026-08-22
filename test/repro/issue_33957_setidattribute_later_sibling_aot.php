<?php
// #33957 — setIdAttribute on a later sibling must register that element's id, not the
// first id= in the loadXML literal (thin-AOT DomUserScriptElementCacheLlvm).
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a><b id="y">2</b></r>');
$e = $d->getElementsByTagName('b')->item(0);
$e->setIdAttribute('id', true);
$y = $d->getElementById('y');
$x = $d->getElementById('x');
if ($y === null) {
    echo "y=null\n";
} else {
    echo 'y=', $y->tagName, "\n";
}
if ($x === null) {
    echo "x=null\n";
} else {
    echo 'x=', $x->tagName, "\n";
}
