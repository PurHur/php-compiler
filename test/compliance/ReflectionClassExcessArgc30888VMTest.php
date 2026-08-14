<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionClass/Function/Parameter excess argc → ArgumentCountError (#30888). */
final class ReflectionClassExcessArgc30888VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflectionclass_30888.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflectionclass_30888.phpt',
            'excess_argc_reflectionclass_30888.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
