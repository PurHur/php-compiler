<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: file-scope const true/false/null are Zend compile fatals (#32228, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class ReservedGlobalConst32228JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'const_true_redeclare_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/const_true_redeclare_compile_fatal.phpt',
            'const_true_redeclare_compile_fatal.phpt'
        );
        yield 'const_false_redeclare_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/const_false_redeclare_compile_fatal.phpt',
            'const_false_redeclare_compile_fatal.phpt'
        );
        yield 'const_null_redeclare_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/const_null_redeclare_compile_fatal.phpt',
            'const_null_redeclare_compile_fatal.phpt'
        );
        yield 'const_TRUE_redeclare_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/const_TRUE_redeclare_compile_fatal.phpt',
            'const_TRUE_redeclare_compile_fatal.phpt'
        );
        yield 'const_true_define_warns.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/const_true_define_warns.phpt',
            'const_true_define_warns.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
