--TEST--
dom DOMDocument::importNode() then getElementById() after appendChild (#19212, ext/dom/node.c)
--FILE--
<?php
$src = new DOMDocument();
$src->loadHTML('<div id="target">x</div>');
$div = $src->getElementById('target');
$target = new DOMDocument();
$target->loadHTML('<!DOCTYPE html><html><body></body></html>');
$n = $target->importNode($div, true);
echo 'attr:', $n->getAttribute('id'), "\n";
$body = $target->getElementsByTagName('body')->item(0);
$body->appendChild($n);
$found = $target->getElementById('target');
echo null !== $found ? 'ok' : 'null', "\n";

$src2 = new DOMDocument();
$src2->loadHTML('<span id="t2">y</span>');
$span = $src2->getElementById('t2');
$target2 = new DOMDocument();
$target2->loadHTML('<html></html>');
$n2 = $target2->importNode($span, true);
$target2->documentElement->appendChild($n2);
$found2 = $target2->getElementById('t2');
echo null !== $found2 ? 'de_ok' : 'de_null', "\n";
--EXPECT--
attr:target
ok
de_ok
