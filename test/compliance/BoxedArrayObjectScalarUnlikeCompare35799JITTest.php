<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: assigned array/object vs native int ordered compare (#35799 leftover of #32503).
 *
 * @group llvm
 */
final class BoxedArrayObjectScalarUnlikeCompare35799JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'boxed_array_object_scalar_unlike_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/boxed_array_object_scalar_unlike_compare.phpt',
            'boxed_array_object_scalar_unlike_compare.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
