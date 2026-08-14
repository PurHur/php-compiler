<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: Generator current/valid/key/next/send excess argc → ArgumentCountError (#30907). */
final class GeneratorExcessArgc30907VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_generator_methods_30907.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_generator_methods_30907.phpt',
            'excess_argc_generator_methods_30907.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
