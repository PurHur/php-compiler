<?php
// @differential-repeat: 10   heap corruption with dead fopen locals + 4-var encapsed is intermittent (#24024)
//
// AOT guard for #23811 string collision — int 2 must not render as Resource id #2 while handles are open.
// Also guards #24024: unused fopen() results plus multi-var interpolation must not free(): invalid pointer.
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
