--TEST--
stdlib number_format() — TypeError for wrong separator/decimal types JIT (#7443)
--FILE--
<?php
$tests = [
    ['decimals string', static fn () => number_format(1234.5, '2')],
    ['dec_separator int', static fn () => number_format(1234.5, 2, 0)],
    ['thousands_separator int', static fn () => number_format(1234.5, 2, '.', 0)],
];

foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo $name, ": uncaught\n";
    } catch (TypeError $e) {
        echo $name, ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
decimals string: number_format(): Argument #2 ($num_decimal_places) must be of type int, string given
dec_separator int: number_format(): Argument #3 ($dec_separator) must be of type string, int given
thousands_separator int: number_format(): Argument #4 ($thousands_separator) must be of type string, int given
