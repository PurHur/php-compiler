<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime(Immutable)::getOffset() (#30761).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeGetOffsetJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_getoffset_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_getoffset_jit.phpt',
            'datetime_getoffset_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
