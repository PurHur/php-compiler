<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Fiber method excess argc → ArgumentCountError (#30906). */
final class FiberExcessArgc30906VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_fiber_30906.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_fiber_30906.phpt',
            'excess_argc_fiber_30906.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
