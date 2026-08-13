<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: idate() excess argc → ArgumentCountError (#30543). */
final class IdateExcessArgc30543VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_idate_30543.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_idate_30543.phpt',
            'excess_argc_idate_30543.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
