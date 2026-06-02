<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_first() / array_last() (#3491). */
final class ArrayFirstLastJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_first_last_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_first_last_jit.phpt',
            'array_first_last_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
