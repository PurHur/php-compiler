<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: ReflectionClass/Function/Parameter excess argc → ArgumentCountError (#30888). */
final class ReflectionClassExcessArgc30888JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflectionclass_30888_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflectionclass_30888_jit.phpt',
            'excess_argc_reflectionclass_30888_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
