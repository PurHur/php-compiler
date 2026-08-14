<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionMethod kind/query excess argc → ArgumentCountError (#31127). */
final class ReflectionMethodKindExcessArgc31127VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_method_kind_31127.phpt' => self::parsePHPT(
            __DIR__.'/cases/reflection/excess_argc_reflection_method_kind_31127.phpt',
            'excess_argc_reflection_method_kind_31127.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
