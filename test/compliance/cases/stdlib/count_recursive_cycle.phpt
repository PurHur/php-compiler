--TEST--
stdlib count() COUNT_RECURSIVE cyclic arrays (issue #10083)
--FILE--
<?php
$a = [];
$a[] = &$a;
$r = count($a, COUNT_RECURSIVE);
echo "self=$r\n";
echo error_get_last()['message'] ?? 'no-warning', "\n";

$b = [];
$c = [];
$b[] = &$c;
$c[] = &$b;
$r2 = count($b, COUNT_RECURSIVE);
echo "ab=$r2\n";
echo error_get_last()['message'] ?? 'no-warning', "\n";

$nested = array(1, array(2, 3));
echo count($nested, COUNT_RECURSIVE), "\n";
--EXPECT--
PHP Warning:  count(): Recursion detected
PHP Warning:  count(): Recursion detected
self=1
count(): Recursion detected
ab=2
count(): Recursion detected
4
