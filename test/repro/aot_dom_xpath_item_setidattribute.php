<?php
// #35447 — AOT: DOMXPath query()->item()->setIdAttribute must use live node + tag attrs
// php-src: ext/dom/xpath.c query; ext/dom/element.c setIdAttribute → xmlAddID
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a><b id="y">2</b></r>');
$xp = new DOMXPath($d);
$e = $xp->query('//b')->item(0);
if ($e === null) {
    echo "tag=null\n";
} else {
    echo 'tag=', $e->nodeName, "\n";
    echo 'attr=', $e->getAttribute('id'), "\n";
    $e->setIdAttribute('id', true);
}
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
