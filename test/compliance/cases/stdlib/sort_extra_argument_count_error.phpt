--TEST--
stdlib sort/rsort/asort/arsort/ksort/krsort excess argc ArgumentCountError (#23855, ext/standard/array.c)
--FILE--
<?php
$fns = ['sort', 'rsort', 'asort', 'arsort', 'ksort', 'krsort'];
foreach ($fns as $fn) {
    try {
        $a = [1];
        $fn($a, SORT_REGULAR, 99);
        echo "{$fn}:uncaught\n";
    } catch (Throwable $e) {
        echo $fn, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
sort ArgumentCountError: sort() expects at most 2 arguments, 3 given
rsort ArgumentCountError: rsort() expects at most 2 arguments, 3 given
asort ArgumentCountError: asort() expects at most 2 arguments, 3 given
arsort ArgumentCountError: arsort() expects at most 2 arguments, 3 given
ksort ArgumentCountError: ksort() expects at most 2 arguments, 3 given
krsort ArgumentCountError: krsort() expects at most 2 arguments, 3 given
