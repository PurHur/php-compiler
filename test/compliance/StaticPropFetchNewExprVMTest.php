<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/**
 * VM compliance for static property fetch when class operand is an object expression (#5477).
 */
final class StaticPropFetchNewExprVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'static_prop_fetch_new_expr' => self::parsePHPT(
            __DIR__ . '/cases/language/static_prop_fetch_new_expr.phpt',
            'static_prop_fetch_new_expr.phpt'
        );
    }
}
