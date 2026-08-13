<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime/DateTimeZone/DateInterval methods excess argc → ArgumentCountError (#30834).
 *
 * @group llvm
 */
final class DateTimeMethodsExcessArgc30834JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_datetime_methods_30834_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_datetime_methods_30834_jit.phpt',
            'excess_argc_datetime_methods_30834_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
