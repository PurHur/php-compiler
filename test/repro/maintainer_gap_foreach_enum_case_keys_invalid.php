<?php

declare(strict_types=1);

/**
 * Maintainer repro for #9792 — enum cases cannot be array keys in php-src-strict.
 *
 * Issue #9792 assumed foreach could iterate enum-keyed arrays built via reflection.
 * Zend rejects enum offsets at assignment (TypeError: Illegal offset type) before foreach.
 * Closing #9792 as invalid — not a foreach-key materialization bug.
 *
 * See also: test/compliance/cases/runtime/array_enum_case_key_typeerror.phpt (#8768).
 */
enum E: int
{
    case A = 1;
    case B = 2;
}

try {
    $arr = [E::A => 'a', E::B => 'b'];
    echo "unexpected: built enum-keyed array\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $arr = [];
    $arr[E::A] = 'a';
    echo "unexpected: assigned enum key\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
