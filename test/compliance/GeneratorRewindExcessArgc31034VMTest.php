<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Generator::rewind() excess argc → ArgumentCountError (#31034). */
final class GeneratorRewindExcessArgc31034VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_rewind_31034.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_rewind_31034.phpt',
            'excess_argc_generator_rewind_31034.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
