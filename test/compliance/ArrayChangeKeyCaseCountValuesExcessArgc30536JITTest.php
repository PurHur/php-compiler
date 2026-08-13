<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: array_change_key_case/array_count_values excess argc → ArgumentCountError (#30536). */
final class ArrayChangeKeyCaseCountValuesExcessArgc30536JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_array_change_key_case_count_values_30536_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_array_change_key_case_count_values_30536_jit.phpt',
            'excess_argc_array_change_key_case_count_values_30536_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
