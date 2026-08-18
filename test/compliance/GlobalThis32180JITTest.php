<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: global $this is Zend compile fatal (#32180, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class GlobalThis32180JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'global_this_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/global_this_compile_fatal.phpt',
            'global_this_compile_fatal.phpt'
        );
        yield 'global_this_method_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/global_this_method_compile_fatal.phpt',
            'global_this_method_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
