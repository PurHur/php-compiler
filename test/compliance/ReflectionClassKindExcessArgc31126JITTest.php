<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionClass kind/query excess argc → ArgumentCountError (#31126). */
final class ReflectionClassKindExcessArgc31126JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_class_kind_31126_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_class_kind_31126_jit.phpt',
            'excess_argc_reflection_class_kind_31126_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
