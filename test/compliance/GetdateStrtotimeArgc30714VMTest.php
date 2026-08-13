<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: getdate/strtotime ArgumentCountError wording (#30714). */
final class GetdateStrtotimeArgc30714VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_getdate_strtotime_30714.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_getdate_strtotime_30714.phpt',
            'excess_argc_getdate_strtotime_30714.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
