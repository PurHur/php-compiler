<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance: backed enum scalar casts mirror VM legacy coerce (#7120). */
final class EnumScalarCastJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_scalar_cast_jit_parity.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_scalar_cast_jit_parity.phpt',
            'enum_scalar_cast_jit_parity.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
