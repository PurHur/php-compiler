--TEST--
stdlib printf()
--FILE--
<?php
$n = printf("%d %s\n", 42, 'ok');
var_dump($n);
echo sprintf("pct=%%\n");
--EXPECT--
42 ok
int(6)
pct=%
