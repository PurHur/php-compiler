<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: sizeof() excess argc -> ArgumentCountError cites sizeof() (#30686). */
final class SizeofExcessArgc30686VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_sizeof_30686.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_sizeof_30686.phpt',
            'excess_argc_sizeof_30686.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
