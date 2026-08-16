<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DateInterval::createFromDateString('@@@') Unexpected character Warning (#31575).
 */
final class DateIntervalCreateFromDateStringBadTokenWarn31575VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dateinterval_createfromdatestring_bad_token_warn.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/dateinterval_createfromdatestring_bad_token_warn.phpt',
            'dateinterval_createfromdatestring_bad_token_warn.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
