<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: array_map() Reflection ?callable $callback + null zip (#25396).
 */
final class ArrayMapReflection25396JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_map_reflection_nullable_callback_25396.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_map_reflection_nullable_callback_25396.phpt',
            'array_map_reflection_nullable_callback_25396.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
