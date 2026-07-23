--TEST--
stdlib serialize() $a=[1]; $a[]=&$a — R: graph matches php-src (#22653, ext/standard/var.c)
--FILE--
<?php
$a = [1];
$a[] = &$a;
$blob = serialize($a);
echo $blob === 'a:2:{i:0;i:1;i:1;a:2:{i:0;i:1;i:1;R:3;}}' ? "ok\n" : ("fail:".$blob."\n");

$b = [];
$b[0] = &$b;
echo serialize($b) === 'a:1:{i:0;a:1:{i:0;R:2;}}' ? "empty_ok\n" : "empty_fail\n";

$x = 1;
$c = [&$x, &$x];
echo serialize($c) === 'a:2:{i:0;i:1;i:1;R:2;}' ? "intref_ok\n" : "intref_fail\n";
?>
--EXPECT--
ok
empty_ok
intref_ok
