<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: foreach as &$this is Zend compile fatal (#32205, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class ForeachByRefThis32205JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_byref_this_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_this_compile_fatal.phpt',
            'foreach_byref_this_compile_fatal.phpt'
        );
        yield 'foreach_this_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_this_compile_fatal.phpt',
            'foreach_this_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
