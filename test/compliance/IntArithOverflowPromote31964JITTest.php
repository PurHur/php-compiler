<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: integer + / * overflow promotes to float (#31964).
 *
 * @group llvm
 */
final class IntArithOverflowPromote31964JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'int_arith_overflow_promote.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/int_arith_overflow_promote.phpt',
            'int_arith_overflow_promote.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
