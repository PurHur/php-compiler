<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for trait instance methods on enum cases (#6623, #5709, #6638).
 *
 * @group llvm
 * @group jit
 */
final class EnumTraitMethodJitTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_trait_use.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_use.phpt',
            'enum_trait_use.phpt'
        );
        yield 'enum_trait_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_method.phpt',
            'enum_trait_method.phpt'
        );
        yield 'enum_case_dynamic_trait_method.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_case_dynamic_trait_method.phpt',
            'enum_case_dynamic_trait_method.phpt'
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
