<?php
// Fixed on AOT — #24162. By-ref int param updates the caller (was empty output).
function addOne(int &$x): void { $x = 9; }
$n = 1;
addOne($n);
echo $n, "\n";
