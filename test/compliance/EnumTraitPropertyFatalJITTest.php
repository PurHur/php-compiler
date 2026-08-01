<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance: enums cannot import trait properties (#26558, re-#6005).
 *
 * @group llvm
 * @group jit
 */
final class EnumTraitPropertyFatalJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_trait_properties_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_properties_fatal.phpt',
            'enum_trait_properties_fatal.phpt'
        );
        yield 'enum_nested_trait_properties_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_nested_trait_properties_fatal.phpt',
            'enum_nested_trait_properties_fatal.phpt'
        );
        yield 'enum_no_properties.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_no_properties.phpt',
            'enum_no_properties.phpt'
        );
        yield 'enum_trait_use.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_trait_use.phpt',
            'enum_trait_use.phpt'
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
