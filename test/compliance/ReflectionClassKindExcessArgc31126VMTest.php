<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionClass kind/query excess argc → ArgumentCountError (#31126). */
final class ReflectionClassKindExcessArgc31126VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_class_kind_31126.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_class_kind_31126.phpt',
            'excess_argc_reflection_class_kind_31126.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
