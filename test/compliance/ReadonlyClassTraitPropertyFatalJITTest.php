<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance: readonly class + non-readonly trait property (#26592).
 *
 * @group llvm
 * @group jit
 */
final class ReadonlyClassTraitPropertyFatalJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'readonly_class_trait_nonreadonly_prop_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_trait_nonreadonly_prop_fatal.phpt',
            'readonly_class_trait_nonreadonly_prop_fatal.phpt'
        );
        yield 'readonly_class_nested_trait_nonreadonly_prop_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_nested_trait_nonreadonly_prop_fatal.phpt',
            'readonly_class_nested_trait_nonreadonly_prop_fatal.phpt'
        );
        yield 'readonly_class_ns_nested_trait_nonreadonly_prop_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_ns_nested_trait_nonreadonly_prop_fatal.phpt',
            'readonly_class_ns_nested_trait_nonreadonly_prop_fatal.phpt'
        );
        yield 'readonly_class_trait_method_only_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_trait_method_only_ok.phpt',
            'readonly_class_trait_method_only_ok.phpt'
        );
        yield 'readonly_class_trait_readonly_prop_ok.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_trait_readonly_prop_ok.phpt',
            'readonly_class_trait_readonly_prop_ok.phpt'
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
