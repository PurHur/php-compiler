<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: use … as self/parent is Zend compile fatal (#32254, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class UseAsSpecialClassName32254JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'use_as_self_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/use_as_self_compile_fatal.phpt',
            'use_as_self_compile_fatal.phpt'
        );
        yield 'use_as_parent_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/use_as_parent_compile_fatal.phpt',
            'use_as_parent_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
