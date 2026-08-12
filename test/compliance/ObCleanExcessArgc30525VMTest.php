<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ob_clean() excess argc → ArgumentCountError (#30525). */
final class ObCleanExcessArgc30525VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_ob_clean_30525.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_ob_clean_30525.phpt',
            'excess_argc_ob_clean_30525.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
