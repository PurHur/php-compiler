<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionClass newInstanceArgs/newInstanceWithoutConstructor excess argc (#30923). */
final class ReflectionClassNewInstanceExcessArgc30923VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_class_newinstance_30923.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_class_newinstance_30923.phpt',
            'excess_argc_reflection_class_newinstance_30923.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
