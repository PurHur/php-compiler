<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for generators (`yield`, issue #167). */
final class GeneratorVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'generator_basic.phpt',
                'generator_nested.phpt',
                'generator_yield_from_array.phpt',
                'generator_yield_keys.phpt',
                'generator_yield_from_generator.phpt',
                'generator_get_return.phpt',
                'generator_get_return_early.phpt',
                'generator_get_return_unreachable_yield.phpt',
            ] as $file
        ) {
            yield $file => self::parsePHPT(
                __DIR__.'/cases/language/'.$file,
                $file
            );
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
