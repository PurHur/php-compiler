<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: foreach by-ref residual after append matches Zend (#26738).
 *
 * @group llvm
 */
final class ForeachByrefAppendResidualJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_byref_append_residual.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_append_residual.phpt',
            'foreach_byref_append_residual.phpt'
        );
        yield 'foreach_byref_unset_residual.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_byref_unset_residual.phpt',
            'foreach_byref_unset_residual.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
