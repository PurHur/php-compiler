<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: mbstring excess argc → at-most ArgumentCountError (#30786). */
final class MbstringExcessArgc30786VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_mbstring_30786.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_mbstring_30786.phpt',
            'excess_argc_mbstring_30786.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
