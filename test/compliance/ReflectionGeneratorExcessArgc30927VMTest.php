<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionGenerator excess argc → ArgumentCountError (#30927). */
final class ReflectionGeneratorExcessArgc30927VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_generator_30927.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_generator_30927.phpt',
            'excess_argc_reflection_generator_30927.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
