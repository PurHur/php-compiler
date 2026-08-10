<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTime::diff(null) TypeError Argument #1 ($targetObject) (#29868). */
final class DateTimeDiffNullStrictVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_null_strict.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_diff_null_strict.phpt',
            'datetime_diff_null_strict.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
