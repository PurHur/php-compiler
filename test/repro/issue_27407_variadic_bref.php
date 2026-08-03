<?php
function f(&...$a) { $a[0] = 9; }
$x = 1; f($x); echo $x, "\n";
