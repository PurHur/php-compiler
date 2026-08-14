<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: true/false param+return TypeError actual is bool on default 8.2 profile (#31160).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class TrueFalseParamReturnTypeErrorActual31160JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'true_false_param_return_typeerror_actual.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/true_false_param_return_typeerror_actual.phpt',
            'true_false_param_return_typeerror_actual.phpt'
        );
        yield 'true_false_param_return_typeerror_actual_84.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/true_false_param_return_typeerror_actual_84.phpt',
            'true_false_param_return_typeerror_actual_84.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
