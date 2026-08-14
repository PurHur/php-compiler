<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mbstring/iconv excess argc → at-most ArgumentCountError (#30891). */
final class MbIconvExcessArgc30891VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_mb_iconv_30891.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_mb_iconv_30891.phpt',
            'excess_argc_mb_iconv_30891.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
