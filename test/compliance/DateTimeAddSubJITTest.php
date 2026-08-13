<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::add/sub + DateTimeImmutable::add/sub (#30760).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeAddSubJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_add_sub_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_add_sub_jit.phpt',
            'datetime_add_sub_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
