<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ctype_* ArgumentCountError wording (#30602). */
final class CtypeExcessArgc30602VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_ctype_30602.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_ctype_30602.phpt',
            'excess_argc_ctype_30602.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
