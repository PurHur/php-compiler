--TEST--
stdlib bcmath optional $scale null soft-null on 8.4 (#21814, ext/bcmath/bcmath.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});
$ops = [
    'bcadd' => static fn () => bcadd('1', '2', null),
    'bcsub' => static fn () => bcsub('5', '2', null),
    'bcmul' => static fn () => bcmul('2', '3', null),
    'bcdiv' => static fn () => bcdiv('1', '3', null),
    'bcmod' => static fn () => bcmod('10', '3', null),
    'bcpow' => static fn () => bcpow('2', '3', null),
    'bcsqrt' => static fn () => bcsqrt('2', null),
    'bccomp' => static fn () => bccomp('1', '2', null),
];
foreach ($ops as $name => $fn) {
    $seen = [];
    echo $name, '=', $fn(), ' depr=', count($seen), "\n";
}
$seen = [];
$pair = bcdivmod('10', '3', null);
echo 'bcdivmod=', $pair[0], ',', $pair[1], ' depr=', count($seen), "\n";
restore_error_handler();
?>
--EXPECT--
bcadd=3 depr=1
bcsub=3 depr=1
bcmul=6 depr=1
bcdiv=0 depr=1
bcmod=1 depr=1
bcpow=8 depr=1
bcsqrt=1 depr=1
bccomp=-1 depr=1
bcdivmod=3,1 depr=1
