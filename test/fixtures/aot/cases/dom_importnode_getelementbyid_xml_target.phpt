--TEST--
AOT: importNode HTML id into XML document — getElementById (#20830)
--FILE--
<?php
$src = new DOMDocument();
$src->loadHTML('<div id="target">z</div>');
$el = $src->getElementById('target');
$dst = new DOMDocument();
$dst->appendChild($dst->createElement('root'));
$n = $dst->importNode($el, true);
echo 'attr:', $n->getAttribute('id'), "\n";
$dst->appendChild($n);
$found = $dst->getElementById('target');
echo null !== $found ? 'ok' : 'null', "\n";
--EXPECT--
attr:target
ok
