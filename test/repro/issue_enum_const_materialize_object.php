<?php
/**
 * Issue #5738: const/define/class const with enum case RHS must store enum singleton.
 *
 * Zend reference: Zend/zend_compile.c zend_compile_const_expr(), zend_constants.c
 */
declare(strict_types=1);

enum E: string {
    case A = 'x';
}

const FILE_C = E::A;

class C {
    public const CLASS_C = E::A;
}

define('DEFINE_C', E::A);

$fail = 0;
foreach (
    [
        'FILE_C' => FILE_C,
        'CLASS_C' => C::CLASS_C,
        'DEFINE_C' => DEFINE_C,
    ] as $label => $value
) {
    $exported = var_export($value, true);
    $expected = '\\E::A';
    if ($exported !== $expected) {
        echo "FAIL {$label} var_export={$exported} expected={$expected}\n";
        $fail = $fail + 1;
    }
}

echo (FILE_C === E::A && C::CLASS_C === E::A && DEFINE_C === E::A) ? "same\n" : "diff\n";

if (0 !== $fail) {
    exit(1);
}

echo "OK\n";
