<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: filter_has_var/filter_input excess argc ArgumentCountError (#30711).
 *
 * @group llvm
 * @group jit
 */
final class FilterHasVarInputArgc30711JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_has_var_input_argc_30711.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/filter_has_var_input_argc_30711.phpt',
            'filter_has_var_input_argc_30711.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
