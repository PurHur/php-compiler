<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DateTimeZone::__construct(null) TypeError Argument #1 (#29827). */
final class DateTimeZoneConstructNullArgIndexVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimezone_construct_null_argindex.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetimezone_construct_null_argindex.phpt',
            'datetimezone_construct_null_argindex.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
