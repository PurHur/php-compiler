<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: array ⊙ int TypeError (#32346).
 *
 * @group llvm
 */
final class ArrayPlusInt32346JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_plus_int.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_plus_int.phpt',
            'array_plus_int.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
