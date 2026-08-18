<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: float ++/-- stays float (#32281).
 *
 * @group llvm
 */
final class FloatIncDecStaysFloat32281JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'float_incdec_stays_float.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/float_incdec_stays_float.phpt',
            'float_incdec_stays_float.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
