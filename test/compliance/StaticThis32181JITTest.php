<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: static $this is Zend compile fatal (#32181, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class StaticThis32181JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'static_this_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_this_compile_fatal.phpt',
            'static_this_compile_fatal.phpt'
        );
        yield 'static_this_method_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/static_this_method_compile_fatal.phpt',
            'static_this_method_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
