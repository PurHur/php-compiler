<?php
function show($a, $b) {
    echo "a=" . json_encode($a) . " b=" . json_encode($b) . "\n";
}
function id($x) { return $x; }
$t = 'hello';
show(id($t), $t[0]);
show(strtoupper($t), $t[1]);
show(strlen($t), $t[0]);
