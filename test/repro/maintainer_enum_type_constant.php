<?php
declare(strict_types=1);

/**
 * Issue #6590: enum type constants — public const on enum declaration (PHP 8.3).
 *
 * Zend reference: Zend/zend_enum.c enum constant table; Zend/zend_compile.c zend_compile_enum
 */
enum E: string {
    case A = 'a';
    public const X = 'x';
}

echo E::X, "\n";
echo constant('E::X'), "\n";
echo defined('E::X') ? "defined\n" : "undefined\n";
