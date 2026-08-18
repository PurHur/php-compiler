<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: numeric-string integer overflow promotes to float (#32426).
 *
 * @group llvm
 */
final class StrIntOverflowPromote32426JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'str_int_overflow_promote.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/str_int_overflow_promote.phpt',
            'str_int_overflow_promote.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
