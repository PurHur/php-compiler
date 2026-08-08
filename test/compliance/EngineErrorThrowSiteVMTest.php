<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for engine Error throw-site / uncaught FiberError header (#28832). */
final class EngineErrorThrowSiteVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'engine_error_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/engine_error_throw_site.phpt',
            'engine_error_throw_site.phpt'
        );
        yield 'uncaught_fiber_error_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uncaught_fiber_error_throw_site.phpt',
            'uncaught_fiber_error_throw_site.phpt'
        );
        yield 'uncaught_arithmetic_error_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uncaught_arithmetic_error_throw_site.phpt',
            'uncaught_arithmetic_error_throw_site.phpt'
        );
        yield 'uncaught_argument_count_error_throw_site.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/uncaught_argument_count_error_throw_site.phpt',
            'uncaught_argument_count_error_throw_site.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
