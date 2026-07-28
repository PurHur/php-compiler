--TEST--
AOT: 4-var encapsed echo with unused fopen locals must not heap-corrupt (#24024)
--FILE--
<?php
$fh = fopen('php://memory', 'r+');
$fh2 = fopen('php://memory', 'r+');
$a = 2;
$b = 3;
$c = 4;
$d = 3;
echo "$a $b $c $d\n";
?>
--EXPECT--
2 3 4 3
