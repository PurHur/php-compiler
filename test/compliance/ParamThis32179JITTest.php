<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: $this as a parameter is Zend compile fatal (#32179, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class ParamThis32179JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'param_this_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/param_this_compile_fatal.phpt',
            'param_this_compile_fatal.phpt'
        );
        yield 'param_this_method_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/param_this_method_compile_fatal.phpt',
            'param_this_method_compile_fatal.phpt'
        );
        yield 'param_this_arrow_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/param_this_arrow_compile_fatal.phpt',
            'param_this_arrow_compile_fatal.phpt'
        );
        yield 'param_this_byref_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/param_this_byref_compile_fatal.phpt',
            'param_this_byref_compile_fatal.phpt'
        );
        yield 'param_this_abstract_method_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/param_this_abstract_method_compile_fatal.phpt',
            'param_this_abstract_method_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
