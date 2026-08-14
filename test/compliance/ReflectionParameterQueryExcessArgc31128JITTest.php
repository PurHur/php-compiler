<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionParameter query excess argc → ArgumentCountError (#31128). */
final class ReflectionParameterQueryExcessArgc31128JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_parameter_query_31128_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/excess_argc_reflection_parameter_query_31128_jit.phpt',
            'excess_argc_reflection_parameter_query_31128_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
