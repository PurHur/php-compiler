<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createFromFormat `!` reset + trailing Trailing data (#31169).
 *
 * @group llvm
 * @group jit
 */
final class CreateFromFormatBangTrailing31169JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_createfromformat_bang_trailing_31169.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_createfromformat_bang_trailing_31169.phpt',
            'datetime_createfromformat_bang_trailing_31169.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
