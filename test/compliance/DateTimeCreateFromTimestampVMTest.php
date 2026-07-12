<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for DateTime::createFromTimestamp() forward profile (#5973, #9984, #18027). */
final class DateTimeCreateFromTimestampVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        if (!CompilerVersion::supportsDateTimeCreateFromTimestamp()) {
            return;
        }
        yield 'datetime_create_from_timestamp.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_create_from_timestamp.phpt',
            'datetime_create_from_timestamp.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
