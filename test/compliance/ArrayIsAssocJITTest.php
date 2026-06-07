<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for array_is_assoc() (#7016). */
final class ArrayIsAssocJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_is_assoc_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_assoc_jit.phpt',
            'array_is_assoc_jit.phpt'
        );
        yield 'array_is_assoc_type_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/array_is_assoc_type_jit.phpt',
            'array_is_assoc_type_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
