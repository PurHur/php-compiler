<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: createFromFormat `+` trailing Trailing data warning (#31170).
 *
 * @group llvm
 * @group jit
 */
final class CreateFromFormatPlusTrailing31170JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_createfromformat_plus_trailing_31170.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_createfromformat_plus_trailing_31170.phpt',
            'datetime_createfromformat_plus_trailing_31170.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
