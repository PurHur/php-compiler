<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT (MCJIT embed / VM-fallback path): foreach(scalar) warning line (#27953).
 *
 * @group llvm
 * @group jit
 */
final class ForeachScalarWarningLineJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'foreach_scalar_warning_line.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/foreach_scalar_warning_line.phpt',
            'foreach_scalar_warning_line.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
