<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: native bool ⊙ int/float (#32337).
 *
 * @group llvm
 */
final class BoolNativeArith32337JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'bool_native_arith.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/bool_native_arith.phpt',
            'bool_native_arith.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
