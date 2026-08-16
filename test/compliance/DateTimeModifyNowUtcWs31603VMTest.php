<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DateTime::modify now/UTC/whitespace (#31603).
 */
final class DateTimeModifyNowUtcWs31603VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_modify_now_utc_ws.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_modify_now_utc_ws.phpt',
            'datetime_modify_now_utc_ws.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
