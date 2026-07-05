--TEST--
stdlib sscanf() — by-reference array element targets (#4512, ext/standard/sscanf.c)
--FILE--
<?php
$a = [];
$n = sscanf('42', '%d', $a[0]);
echo $n, ' ', $a[0], "\n";
$b = [];
$c = 0;
$n2 = sscanf('10 20', '%d %d', $b[0], $c);
echo $n2, ' ', $b[0], ' ', $c, "\n";
--EXPECT--
1 42
2 10 20
