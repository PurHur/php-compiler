<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance: readonly class + non-readonly trait property (#26592).
 */
final class ReadonlyClassTraitPropertyFatalVMTest extends BaseTest
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
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
