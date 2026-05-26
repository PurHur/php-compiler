--TEST--
AOT: random_int() (#2330)
--FILE--
<?php
$n = random_int(2, 5);
$ok = 1;
if ($n < 2) {
    $ok = 0;
}
if ($n > 5) {
    $ok = 0;
}
echo $ok, "\n";
--EXPECT--
1
