<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: flock() excess argc → ArgumentCountError (#30583). */
final class FlockExcessArgc30583VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_flock_30583.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_flock_30583.phpt',
            'excess_argc_flock_30583.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
