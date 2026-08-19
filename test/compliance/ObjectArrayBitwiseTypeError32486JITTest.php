<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: native-object/array bitwise &|^~ and <<>> TypeError (#32486).
 *
 * @group llvm
 */
final class ObjectArrayBitwiseTypeError32486JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'object_array_bitwise_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/object_array_bitwise_typeerror.phpt',
            'object_array_bitwise_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
