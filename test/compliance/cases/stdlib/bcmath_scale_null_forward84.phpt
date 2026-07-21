--TEST--
stdlib bcmath optional $scale null soft-null on 8.4 (#21814, ext/bcmath/bcmath.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$dep = 0;
set_error_handler(static function (int $no, string $msg) use (&$dep): bool {
    if (E_DEPRECATED === $no && str_contains($msg, 'Passing null')) {
        ++$dep;

        return true;
    }

    return false;
});
$checks = [
    'bcdiv' => static fn () => bcdiv('1', '3', null),
    'bcadd' => static fn () => bcadd('1', '2', null),
    'bcsub' => static fn () => bcsub('5', '2', null),
    'bcmul' => static fn () => bcmul('2', '3', null),
    'bcmod' => static fn () => bcmod('10', '3', null),
    'bcpow' => static fn () => bcpow('2', '3', null),
    'bcsqrt' => static fn () => bcsqrt('2', null),
    'bccomp' => static fn () => bccomp('1', '2', null),
];
foreach ($checks as $name => $fn) {
    $dep = 0;
    $r = $fn();
    echo $name, '=', $r, ' dep=', $dep, "\n";
}
$dep = 0;
[$q, $r] = bcdivmod('10', '3', null);
echo "bcdivmod=$q,$r dep=$dep\n";
try {
    bcdiv('1', '1', 'x');
    echo "bad_type uncaught\n";
} catch (TypeError $e) {
    echo "bad_type TypeError\n";
}
?>
--EXPECT--
bcdiv=0 dep=1
bcadd=3 dep=1
bcsub=3 dep=1
bcmul=6 dep=1
bcmod=1 dep=1
bcpow=8 dep=1
bcsqrt=1 dep=1
bccomp=-1 dep=1
bcdivmod=3,1 dep=1
bad_type TypeError
