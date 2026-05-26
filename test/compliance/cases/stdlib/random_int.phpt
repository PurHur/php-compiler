--TEST--
stdlib random_int() (#2330)
--FILE--
<?php
$n = random_int(1, 6);
$ok = 1;
if ($n < 1) {
    $ok = 0;
}
if ($n > 6) {
    $ok = 0;
}
echo $ok, "\n";
echo random_int(4, 4), "\n";
--EXPECT--
1
4
