<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: Fiber method excess argc → ArgumentCountError (#30906).
 *
 * @group llvm
 */
final class FiberExcessArgc30906JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_fiber_30906_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_fiber_30906_jit.phpt',
            'excess_argc_fiber_30906_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
