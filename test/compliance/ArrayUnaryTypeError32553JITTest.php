<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: unary +/- on array TypeError (#32553).
 *
 * @group llvm
 */
final class ArrayUnaryTypeError32553JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'array_unary_typeerror.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/array_unary_typeerror.phpt',
            'array_unary_typeerror.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
