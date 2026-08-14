<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionClass getMethods/getProperties/getConstructor excess argc (#31033). */
final class ReflectionClassGetMethodsExcessArgc31033VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_class_getmethods_31033.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_class_getmethods_31033.phpt',
            'excess_argc_reflection_class_getmethods_31033.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
