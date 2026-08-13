<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: Generator getReturn/throw excess argc → ArgumentCountError (#30866). */
final class GeneratorExcessArgc30866JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_getreturn_throw_30866_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_getreturn_throw_30866_jit.phpt',
            'excess_argc_generator_getreturn_throw_30866_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
