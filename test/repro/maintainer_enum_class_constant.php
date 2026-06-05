<?php
declare(strict_types=1);

/**
 * Issue #6407: enum user class constants — E::X must resolve (zend_enum.c, zend_constants.c).
 *
 * Zend reference: Zend/zend_enum.c enum constant table; Zend/zend_constants.c zend_fetch_class_constant_ex()
 */
enum E {
    case A;
    public const X = 42;
}

echo E::X, "\n";
echo constant('E::X'), "\n";
