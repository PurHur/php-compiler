<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: function-local static Closures stay callable across calls (#28039). */
final class FunctionStaticClosureCallableJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'function_static_closure_callable.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/function_static_closure_callable.phpt',
            'function_static_closure_callable.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
