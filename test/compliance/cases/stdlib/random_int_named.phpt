--TEST--
stdlib random_int() min:/max: named parameters (#10043, ext/random/random.c)
--FILE--
<?php
$n = random_int(min: 1, max: 3);
echo ($n >= 1 && $n <= 3) ? "ok\n" : "fail\n";
--EXPECT--
ok
