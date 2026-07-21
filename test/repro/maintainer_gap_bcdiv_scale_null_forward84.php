<?php
/**
 * #21814 — bcdiv()/bcadd() optional $scale null under PHP_COMPILER_PROFILE=8.4.
 *
 * php-src: ext/bcmath/bcmath.stub.php ?int $scale soft-null.
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if (E_DEPRECATED === $no) {
        $deps[] = $msg;
    }

    return true;
});

$fail = 0;

$r = bcdiv('1', '3', null);
if ('0' !== $r) {
    echo "bcdiv result=$r want=0\n";
    ++$fail;
}
if (1 !== count($deps) || !str_contains($deps[0], 'bcdiv(): Passing null to parameter #3 ($scale)')) {
    echo 'bcdiv dep=', json_encode($deps), "\n";
    ++$fail;
}

$deps = [];
$pair = bcdivmod('10', '3', null);
if (!is_array($pair) || '3' !== $pair[0] || '1' !== $pair[1]) {
    echo 'bcdivmod result=', var_export($pair, true), "\n";
    ++$fail;
}
if (1 !== count($deps) || !str_contains($deps[0], 'bcdivmod(): Passing null to parameter #3 ($scale)')) {
    echo 'bcdivmod dep=', json_encode($deps), "\n";
    ++$fail;
}

restore_error_handler();
exit($fail === 0 ? 0 : 1);
