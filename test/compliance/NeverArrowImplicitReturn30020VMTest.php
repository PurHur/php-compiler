<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM: arrow fn(): never => expr TypeErrors at call time (#30020, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class NeverArrowImplicitReturn30020VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'never_arrow_implicit_return.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/never_arrow_implicit_return.phpt',
            'never_arrow_implicit_return.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
