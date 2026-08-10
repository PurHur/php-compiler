--TEST--
AOT sort()/rsort()/ksort()/krsort() null $flags coerce to SORT_REGULAR (#29385)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
$a = [3, 1, 2];
sort($a, null);
echo implode(',', $a), "\n";
$b = [3, 1, 2];
rsort($b, null);
echo implode(',', $b), "\n";
$e = [3, 1, 2];
ksort($e, null);
echo implode(',', $e), "\n";
$f = [3, 1, 2];
krsort($f, null);
echo implode(',', $f), "\n";
--EXPECT--
1,2,3
3,2,1
3,1,2
2,1,3
