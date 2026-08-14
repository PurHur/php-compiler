<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionEnum isBacked/getBackingType excess argc → ArgumentCountError (#30929). */
final class ReflectionEnumIsBackedExcessArgc30929JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_enum_isbacked_30929_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_enum_isbacked_30929_jit.phpt',
            'excess_argc_reflection_enum_isbacked_30929_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
