<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DateInterval::createFromDateString("") throws on PROFILE=8.4 (#29290). */
final class DateIntervalCreateFromDateStringEmptyJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dateinterval_create_from_date_string_empty_forward84_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dateinterval_create_from_date_string_empty_forward84_jit.phpt',
            'dateinterval_create_from_date_string_empty_forward84_jit.phpt'
        );
    }

    public function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }

    public function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }
}
