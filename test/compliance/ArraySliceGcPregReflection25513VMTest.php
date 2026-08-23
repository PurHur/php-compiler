<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: array_slice/gc_status/preg_replace_callback_array Reflection match Zend stubs (#25513).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ArraySliceGcPregReflection25513VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_slice_gc_preg_reflection_25513.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_slice_gc_preg_reflection_25513.phpt',
            'array_slice_gc_preg_reflection_25513.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
