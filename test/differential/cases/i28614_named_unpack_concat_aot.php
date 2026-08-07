<?php
// @differential-repeat: 10   AOT script-global concat/encapsed was SXE readObject mis-fold (#28614)
function f($a, $b) { echo "$a-$b\n"; }
f(...['b' => 2, 'a' => 1]);
$s = 'x';
echo $s."y\n";
