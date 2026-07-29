<?php

declare(strict_types=1);

/**
 * Repro #24612 — BcMath\Number::powmod under PROFILE=8.4 (php-src-strict).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24612_bcmath_number_powmod.php
 *
 * Error-path cases use fresh Number instances — sequential ValueError throws on the
 * same receiver can leave `$value` unreadable under the VM (pre-existing).
 */

use BcMath\Number;

$n = new Number('10');
if (!method_exists($n, 'powmod')) {
    fwrite(STDERR, "FAIL: method_exists powmod false\n");
    exit(1);
}

$p = $n->powmod('3', '7');
if ('6' !== $p->value || 0 !== $p->scale) {
    fwrite(STDERR, 'FAIL: 10^3 mod 7 => '.$p->value.' scale '.$p->scale."\n");
    exit(1);
}

$p2 = $n->powmod('3', '7', 2);
if ('6.00' !== $p2->value || 2 !== $p2->scale) {
    fwrite(STDERR, 'FAIL: powmod scale=2 => '.$p2->value.' scale '.$p2->scale."\n");
    exit(1);
}

$p3 = (new Number('1.0'))->powmod('2', '7');
if ('1' !== $p3->value) {
    fwrite(STDERR, 'FAIL: trailing-zero base => '.$p3->value."\n");
    exit(1);
}

$p4 = (new Number('-10'))->powmod('3', '7');
if ('-6' !== $p4->value) {
    fwrite(STDERR, 'FAIL: neg base => '.$p4->value."\n");
    exit(1);
}

try {
    (new Number('1.5'))->powmod('2', '7');
    fwrite(STDERR, "FAIL: expected base fractional ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ('Base number cannot have a fractional part' !== $e->getMessage()) {
        fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    (new Number('10'))->powmod('-1', '7');
    fwrite(STDERR, "FAIL: expected negative exponent ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if (!str_contains($e->getMessage(), 'must be greater than or equal to 0')) {
        fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    (new Number('10'))->powmod('3', '0');
    fwrite(STDERR, "FAIL: expected Modulo by zero\n");
    exit(1);
} catch (DivisionByZeroError $e) {
    if ('Modulo by zero' !== $e->getMessage()) {
        fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
