<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: DateTimeImmutable::modify('@@@') Unexpected character Warning (#31597).
 */
final class DateTimeImmutableModifyBadTokenWarn31597VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetimeimmutable_modify_bad_token_warn.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetimeimmutable_modify_bad_token_warn.phpt',
            'datetimeimmutable_modify_bad_token_warn.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
