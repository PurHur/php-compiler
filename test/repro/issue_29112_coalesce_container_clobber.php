<?php
// Repro #29112 — dim/prop ?? and ??= must not replace the container with the RHS.
$a = [];
$a['x'] ??= 'y';
echo gettype($a), '|', json_encode($a), "\n";

$a = [];
$x = $a['x'] ?? 'y';
echo gettype($a), '|', json_encode($a), '|', $x, "\n";

$o = new stdClass;
$o->p ??= 'z';
echo gettype($o), '|', json_encode($o), "\n";

$a = [];
$a['x']['y'] ??= 1;
echo gettype($a), '|', json_encode($a), "\n";
