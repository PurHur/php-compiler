<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ReflectionFiber excess argc ACE + getTrace TypeError (#30928).
 *
 * Fiber scripts VM-fallback in bin/jit.php (#2114); still guards the shared VM builtins.
 *
 * @group llvm
 */
final class ReflectionFiberExcessArgc30928JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_fiber_30928.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_fiber_30928.phpt',
            'excess_argc_reflection_fiber_30928.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
