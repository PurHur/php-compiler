--TEST--
AOT: DOMDocument::importNode() + getElementById() after appendChild (#19212)
--FILE--
<?php
$src = new DOMDocument();
$src->loadHTML('<div id="target">x</div>');
$div = $src->getElementById('target');
$target = new DOMDocument();
$target->loadHTML('<p id="other">z</p>');
$n = $target->importNode($div, true);
echo 'attr:', $n->getAttribute('id'), "\n";
$target->appendChild($n);
$found = $target->getElementById('target');
echo null !== $found ? 'ok' : 'null', "\n";
--EXPECT--
attr:target
ok
