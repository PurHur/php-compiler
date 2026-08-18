<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: over-deep / non-int continue|break is Zend compile fatal (#32207, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ContinueOverdeep32207VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'continue_overdeep_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/continue_overdeep_compile_fatal.phpt',
            'continue_overdeep_compile_fatal.phpt'
        );
        yield 'break_overdeep_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/break_overdeep_compile_fatal.phpt',
            'break_overdeep_compile_fatal.phpt'
        );
        yield 'continue_float_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/continue_float_compile_fatal.phpt',
            'continue_float_compile_fatal.phpt'
        );
        yield 'continue_zero.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/continue_zero.phpt',
            'continue_zero.phpt'
        );
        yield 'break_zero.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/break_zero.phpt',
            'break_zero.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
