<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionMethod kind/query excess argc → ArgumentCountError (#31127). */
final class ReflectionMethodKindExcessArgc31127JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_method_kind_31127_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/excess_argc_reflection_method_kind_31127_jit.phpt',
            'excess_argc_reflection_method_kind_31127_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
