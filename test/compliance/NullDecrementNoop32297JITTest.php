<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: --$null stays NULL (#32297).
 *
 * @group llvm
 */
final class NullDecrementNoop32297JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'null_decrement_noop.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/null_decrement_noop.phpt',
            'null_decrement_noop.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
