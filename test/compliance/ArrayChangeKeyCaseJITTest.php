<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_change_key_case(). */
final class ArrayChangeKeyCaseJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_change_key_case_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_change_key_case_jit.phpt',
            'array_change_key_case_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
