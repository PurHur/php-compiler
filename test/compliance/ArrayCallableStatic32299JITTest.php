<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: array callable ['Class','method']() (#32299).
 *
 * @group llvm
 */
final class ArrayCallableStatic32299JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_callable_static_invoke.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_callable_static_invoke.phpt',
            'array_callable_static_invoke.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
