<?php
/** Repro #25119: AOT loadHTML/importNode must compile (LLVM verify) and reindex getElementById. */
$src = new DOMDocument();
$src->loadHTML('<div id="target">x</div>');
$div = $src->getElementById('target');
echo null !== $div ? "src_ok\n" : "src_null\n";

$target = new DOMDocument();
$target->loadHTML('<p id="other">z</p>');
$n = $target->importNode($div, true);
echo 'attr:', $n->getAttribute('id'), "\n";
$target->appendChild($n);
$found = $target->getElementById('target');
echo null !== $found ? "ok\n" : "null\n";
