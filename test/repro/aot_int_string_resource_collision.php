<?php
// Issue #23811: int-to-string must not consult resource registry for proven integers.
$fh = fopen('php://memory', 'r+');
$fh2 = fopen('php://memory', 'r+');
$a = 1;
++$a;
$b = 2;
++$b;
$c = 3;
++$c;
$d = 4;
--$d;
echo "$a $b $c $d\n";
