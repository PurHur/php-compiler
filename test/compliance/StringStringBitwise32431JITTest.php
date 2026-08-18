<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: string⊙string bitwise (#32431).
 *
 * @group llvm
 */
final class StringStringBitwise32431JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'string_string_bitwise.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/string_string_bitwise.phpt',
            'string_string_bitwise.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
