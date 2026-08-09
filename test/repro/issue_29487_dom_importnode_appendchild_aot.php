<?php
/** Repro #29487 — AOT importNode + appendChild after loadHTML (re-#19212). */
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
