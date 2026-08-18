<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: continue/break 0 is Zend compile fatal (#32155, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class ContinueZero32155JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
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
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
