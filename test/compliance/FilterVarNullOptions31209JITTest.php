<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: filter_var null $options under strict_types → TypeError (#31209).
 */
final class FilterVarNullOptions31209JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'filter_var_null_options_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/filter/filter_var_null_options_strict_jit.phpt',
            'filter_var_null_options_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
