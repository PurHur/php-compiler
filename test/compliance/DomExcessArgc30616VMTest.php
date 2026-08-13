<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOM instance methods excess argc → ArgumentCountError (#30616). */
final class DomExcessArgc30616VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_dom_30616.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_dom_30616.phpt',
            'excess_argc_dom_30616.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
