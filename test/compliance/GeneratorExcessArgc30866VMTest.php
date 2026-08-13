<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Generator getReturn/throw excess argc → ArgumentCountError (#30866). */
final class GeneratorExcessArgc30866VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_getreturn_throw_30866.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_getreturn_throw_30866.phpt',
            'excess_argc_generator_getreturn_throw_30866.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
