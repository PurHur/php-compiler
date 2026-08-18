<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: bare $GLOBALS writes are Zend compile fatal (#32229, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class GlobalsWrite32229VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'globals_assign_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/globals_assign_compile_fatal.phpt',
            'globals_assign_compile_fatal.phpt'
        );
        yield 'globals_plus_assign_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/globals_plus_assign_compile_fatal.phpt',
            'globals_plus_assign_compile_fatal.phpt'
        );
        yield 'unset_globals_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/unset_globals_compile_fatal.phpt',
            'unset_globals_compile_fatal.phpt'
        );
        yield 'globals_append_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/globals_append_compile_fatal.phpt',
            'globals_append_compile_fatal.phpt'
        );
        yield 'globals_dim_assign_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/globals_dim_assign_ok.phpt',
            'globals_dim_assign_ok.phpt'
        );
        yield 'globals_acquire_reference_reject.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/globals_acquire_reference_reject.phpt',
            'globals_acquire_reference_reject.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
