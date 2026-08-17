<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: ArrayAccess by-ref offsetGet assign-op uses the live int (#31947).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 *
 * @group llvm
 */
final class ArrayAccessAssignOp31947JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'arrayaccess_assign_op_byref.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/arrayaccess_assign_op_byref.phpt',
            'arrayaccess_assign_op_byref.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
