<?php
/** Repro #19870 — getElementById live after setAttribute/removeAttribute id (AOT-safe). */
$doc = new DOMDocument();
$doc->loadHTML('<p id="a">x</p>');
$el = $doc->getElementById('a');
echo 'before=', (null !== $el ? $el->textContent : 'null'), "\n";
$el->setAttribute('id', 'b');
$b = $doc->getElementById('b');
echo 'after_b=', (null !== $b ? '1' : '0'), "\n";
$a = $doc->getElementById('a');
echo 'after_a=', (null !== $a ? '1' : '0'), "\n";
$el->removeAttribute('id');
$a2 = $doc->getElementById('a');
echo 'after_rm_a=', (null !== $a2 ? '1' : '0'), "\n";
$b2 = $doc->getElementById('b');
echo 'after_rm_b=', (null !== $b2 ? '1' : '0'), "\n";
