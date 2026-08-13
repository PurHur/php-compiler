--TEST--
stdlib JIT: array_fill/array_fill_keys/range ArgumentCountError wording (#30719)
--FILE--
<?php
foreach ([
    'fill_hi' => static fn () => array_fill(0, 1, 'a', 4),
    'fill_lo' => static fn () => array_fill(0, 1),
    'keys_hi' => static fn () => array_fill_keys([1], 'a', 3),
    'keys_lo' => static fn () => array_fill_keys([1]),
    'range_hi' => static fn () => range(1, 3, 1, 4),
    'range_lo' => static fn () => range(1),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$f = array_fill(0, 1, 'a');
echo 'ok_fill=', ($f[0] === 'a') ? '1' : '0', "\n";
$k = array_fill_keys([1], 'a');
echo 'ok_keys=', ($k[1] === 'a') ? '1' : '0', "\n";
$r = range(1, 3);
echo 'ok_range=', ($r === [1, 2, 3]) ? '1' : '0', "\n";
--EXPECT--
fill_hi ArgumentCountError: array_fill() expects exactly 3 arguments, 4 given
fill_lo ArgumentCountError: array_fill() expects exactly 3 arguments, 2 given
keys_hi ArgumentCountError: array_fill_keys() expects exactly 2 arguments, 3 given
keys_lo ArgumentCountError: array_fill_keys() expects exactly 2 arguments, 1 given
range_hi ArgumentCountError: range() expects at most 3 arguments, 4 given
range_lo ArgumentCountError: range() expects at least 2 arguments, 1 given
ok_fill=1
ok_keys=1
ok_range=1
