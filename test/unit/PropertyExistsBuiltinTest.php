<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for property_exists() (issue #1372). */
final class PropertyExistsBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach ([
            'property_exists.phpt',
            'property_exists_jit.phpt',
            'property_exists_case_sensitive.phpt',
            'property_exists_case_sensitive_jit.phpt',
            'property_exists_null.phpt',
            'property_exists_null_jit.phpt',
            'property_exists_private_parent.phpt',
            'property_exists_private_parent_jit.phpt',
            'unset_dynamic_property_exists.phpt',
            'unset_dynamic_property_exists_jit.phpt',
        ] as $file) {
            $path = __DIR__.'/../compliance/cases/stdlib/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }
}
