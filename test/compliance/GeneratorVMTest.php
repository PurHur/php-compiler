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
                'generator_yield_from_iterator.phpt',
                'generator_yield_from_non_traversable.phpt',
                'generator_yield_from_string.phpt',
                'generator_iterator_protocol.phpt',
                'generator_get_return.phpt',
                'generator_get_return_early.phpt',
                'generator_get_return_before_yield.phpt',
                'generator_get_return_after_throw.phpt',
                'generator_throw_after_yield.phpt',
                'generator_throw_stack.phpt',
                'generator_send_after_current.phpt',
                'generator_get_return_unreachable_yield.phpt',
                'generator_try_catch_jit.phpt',
                'generator_current_enum_case.phpt',
                'generator_yield_from_enum.phpt',
                'generator_yield_from_foreach.phpt',
                'iterator_to_array_generator_key_collision.phpt',
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
