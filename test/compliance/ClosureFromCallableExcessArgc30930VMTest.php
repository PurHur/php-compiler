<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Closure::fromCallable excess argc → ArgumentCountError (#30930). */
final class ClosureFromCallableExcessArgc30930VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_closure_fromcallable_30930.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_closure_fromcallable_30930.phpt',
            'excess_argc_closure_fromcallable_30930.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
