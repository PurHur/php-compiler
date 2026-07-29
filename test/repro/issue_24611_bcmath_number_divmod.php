<?php

declare(strict_types=1);

/**
 * Repro #24611 — BcMath\Number::divmod under PROFILE=8.4 (php-src-strict).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_24611_bcmath_number_divmod.php
 */

use BcMath\Number;

$n = new Number('10');
if (!method_exists($n, 'divmod')) {
    fwrite(STDERR, "FAIL: method_exists divmod false\n");
    exit(1);
}

$r = $n->divmod('3');
if (!\is_array($r) || 2 !== \count($r)) {
    fwrite(STDERR, "FAIL: return shape\n");
    exit(1);
}
if (!$r[0] instanceof Number || !$r[1] instanceof Number) {
    fwrite(STDERR, "FAIL: elements not Number\n");
    exit(1);
}
if ('3' !== $r[0]->value || '1' !== $r[1]->value || 0 !== $r[0]->scale || 0 !== $r[1]->scale) {
    fwrite(STDERR, 'FAIL: 10 divmod 3 => '.$r[0]->value.','.$r[1]->value
        .' scales '.$r[0]->scale.','.$r[1]->scale."\n");
    exit(1);
}

$n2 = new Number('10.5');
$r2 = $n2->divmod('2.5');
if ('4' !== $r2[0]->value || '0.5' !== $r2[1]->value || 0 !== $r2[0]->scale || 1 !== $r2[1]->scale) {
    fwrite(STDERR, 'FAIL: 10.5 divmod 2.5 => '.$r2[0]->value.','.$r2[1]->value
        .' scales '.$r2[0]->scale.','.$r2[1]->scale."\n");
    exit(1);
}

$r3 = $n2->divmod('2.5', 2);
if ('4' !== $r3[0]->value || '0.50' !== $r3[1]->value || 0 !== $r3[0]->scale || 2 !== $r3[1]->scale) {
    fwrite(STDERR, 'FAIL: divmod scale=2 => '.$r3[0]->value.','.$r3[1]->value
        .' scales '.$r3[0]->scale.','.$r3[1]->scale."\n");
    exit(1);
}

try {
    $n->divmod('0');
    fwrite(STDERR, "FAIL: expected DivisionByZeroError\n");
    exit(1);
} catch (DivisionByZeroError $e) {
    if ('Division by zero' !== $e->getMessage()) {
        fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
