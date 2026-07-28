<?php
// @differential-repeat: 10   AOT unpack into fixed-arity was intermittent heap corruption / wrong output (#24144)
function f($a, $b) { echo "$a-$b\n"; }
f(...[1, 2]);
$p = [3, 4];
f(...$p);
