<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for gettimeofday() (#3208). */
final class GettimeofdayVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'gettimeofday.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/gettimeofday.phpt',
            'gettimeofday.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
