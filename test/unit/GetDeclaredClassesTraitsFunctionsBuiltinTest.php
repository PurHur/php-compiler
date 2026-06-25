<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM/JIT/AOT compliance for get_declared_* / get_defined_functions (issue #3128). */
final class GetDeclaredClassesTraitsFunctionsBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $root = __DIR__.'/../compliance/cases/stdlib';
        foreach ([
            'get_declared_classes.phpt',
            'get_declared_classes_jit.phpt',
            'get_declared_classes_includes_internal.phpt',
            'get_declared_classes_includes_internal_jit.phpt',
            'get_declared_traits.phpt',
            'get_declared_traits_jit.phpt',
            'get_declared_functions.phpt',
            'get_declared_functions_jit.phpt',
            'get_defined_functions.phpt',
            'get_defined_functions_jit.phpt',
        ] as $file) {
            yield $file => self::parsePHPT($root.'/'.$file, $file);
        }
    }

    public function testAotFixturesExist(): void
    {
        $root = __DIR__.'/../fixtures/aot/cases';
        foreach ([
            'get_declared_classes.phpt',
            'get_declared_classes_includes_internal.phpt',
            'get_declared_traits.phpt',
            'get_declared_functions.phpt',
            'get_defined_functions.phpt',
        ] as $file) {
            $this->assertFileExists($root.'/'.$file);
        }
    }
}
