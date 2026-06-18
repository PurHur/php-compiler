<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for backed enum relational operators (#8897, #9016, zend_enum.c).
 *
 * @group llvm
 * @group jit
 */
final class EnumRelationalOperatorsJitTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_relational_operators.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_relational_operators.phpt',
            'enum_relational_operators.phpt'
        );
        yield 'enum_relational_scalar.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_relational_scalar.phpt',
            'enum_relational_scalar.phpt'
        );
        yield 'enum_scalar_compare.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_scalar_compare.phpt',
            'enum_scalar_compare.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
