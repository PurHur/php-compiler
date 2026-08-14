<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Generator current/valid/key/next/send excess argc → ArgumentCountError (#30907). */
final class GeneratorExcessArgc30907JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_methods_30907_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_methods_30907_jit.phpt',
            'excess_argc_generator_methods_30907_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
