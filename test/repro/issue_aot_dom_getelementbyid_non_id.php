<?php
// #31367 — AOT getElementById on plain id (not ID-typed) must return null, not segfault.
$d = new DOMDocument();
$d->loadXML('<r><a id="x">1</a></r>');
$n = $d->getElementById('x');
echo $n === null ? "null\n" : ("found=" . $n->textContent . "\n");
