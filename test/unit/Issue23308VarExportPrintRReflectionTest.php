<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * var_export/print_r Reflection names value (not var) (#23308).
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
final class Issue23308VarExportPrintRReflectionTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['value', 'return='], BuiltinParamNames::forFunction('var_export'));
        self::assertSame(['value', 'return='], BuiltinParamNames::forFunction('print_r'));
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('var_export'),
            'value',
            'var_export'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('print_r'),
            'var',
            'print_r'
        ));
    }

    public function testVmNamedValueMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23308_var_export_print_r_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23308_var_export_print_r_reflection.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "var_export:value,return\n"
            ."print_r:value,return\n"
            ."array (\n  'a' => 1,\n)\n"
            ."Array\n(\n    [0] => 1\n)\n"
            ."Unknown named parameter \$var\n",
            $out
        );
    }
}
