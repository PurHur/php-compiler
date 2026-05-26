--TEST--
JIT: random_int() (#2330)
--FILE--
<?php
$n = random_int(0, 3);
$ok = 1;
if ($n < 0) {
    $ok = 0;
}
if ($n > 3) {
    $ok = 0;
}
echo $ok, "\n";
--EXPECT--
1
