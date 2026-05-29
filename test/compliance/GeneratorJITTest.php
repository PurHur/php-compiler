<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT/AOT does not lower generators yet (issue #167). */
final class GeneratorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'generator_basic.phpt',
            'generator_nested.phpt',
            'generator_yield_from_array.phpt',
            'generator_yield_keys.phpt',
            'generator_yield_from_generator.phpt',
        ] as $file) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}

