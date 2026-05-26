<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM compliance for unset() on locals, arrays, and object properties (#2273).
 */
final class UnsetComplianceTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $dir = __DIR__.'/../compliance/cases/language';
        foreach ([
            'unset_var.phpt',
            'unset_variable.phpt',
            'unset_array.phpt',
            'unset_array_string_key.phpt',
            'unset_array_int_key.phpt',
            'unset_property.phpt',
            'unset_this_property.phpt',
            'unset_multi.phpt',
        ] as $file) {
            yield $file => self::parsePHPT($dir.'/'.$file, $file);
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
