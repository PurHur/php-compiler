--TEST--
stdlib cast/array wrong argc JIT — ArgumentCountError not LogicException (#23165)
--FILE--
<?php
declare(strict_types=1);

$cases = [
    'boolval' => static function () { boolval(); },
    'strval' => static function () { strval(); },
    'floatval' => static function () { floatval(); },
    'array_sum' => static function () { array_sum(); },
    'array_flip' => static function () { array_flip(); },
    'array_keys' => static function () { array_keys(); },
    'array_values' => static function () { array_values(); },
    'array_unique' => static function () { array_unique(); },
    'array_reverse' => static function () { array_reverse(); },
    'array_pop' => static function () { array_pop(); },
    'array_shift' => static function () { array_shift(); },
    'array_keys_too_many' => static function () { array_keys([], 1, true, 4); },
    'array_unique_too_many' => static function () { array_unique([], SORT_STRING, 1); },
    'array_reverse_too_many' => static function () { array_reverse([], true, 1); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, " ran\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
boolval ArgumentCountError: boolval() expects exactly 1 argument, 0 given
strval ArgumentCountError: strval() expects exactly 1 argument, 0 given
floatval ArgumentCountError: floatval() expects exactly 1 argument, 0 given
array_sum ArgumentCountError: array_sum() expects exactly 1 argument, 0 given
array_flip ArgumentCountError: array_flip() expects exactly 1 argument, 0 given
array_keys ArgumentCountError: array_keys() expects at least 1 argument, 0 given
array_values ArgumentCountError: array_values() expects exactly 1 argument, 0 given
array_unique ArgumentCountError: array_unique() expects at least 1 argument, 0 given
array_reverse ArgumentCountError: array_reverse() expects at least 1 argument, 0 given
array_pop ArgumentCountError: array_pop() expects exactly 1 argument, 0 given
array_shift ArgumentCountError: array_shift() expects exactly 1 argument, 0 given
array_keys_too_many ArgumentCountError: array_keys() expects at most 3 arguments, 4 given
array_unique_too_many ArgumentCountError: array_unique() expects at most 2 arguments, 3 given
array_reverse_too_many ArgumentCountError: array_reverse() expects at most 2 arguments, 3 given
