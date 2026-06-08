<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for random_bytes() enum strict guards (#6160).
 *
 * @group llvm
 */
final class RandomBytesJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'random_bytes_enum_typeerror_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/random_bytes_enum_typeerror_jit.phpt',
            'random_bytes_enum_typeerror_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
