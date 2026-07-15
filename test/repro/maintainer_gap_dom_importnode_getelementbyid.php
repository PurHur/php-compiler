<?php
declare(strict_types=1);

// #19212 — importNode copies id attrs but target getElementById must work after append
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
echo null === $found ? "null\n" : "ok\n";
