<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Split-compilation string_const_/array_const_ names must not share bare
 * string_const_N across helper .o files (#26411 / #15889).
 */
final class ModuleLocalConstGlobalNameTest extends TestCase
{
    public function testModuleLocalConstGlobalNameAppendsInitSymbolSuffix(): void
    {
        $prev = getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX=_unit_test_suffix_26411');
        try {
            $ref = new ReflectionClass(JIT\Context::class);
            $ctx = $ref->newInstanceWithoutConstructor();
            $method = $ref->getMethod('moduleLocalConstGlobalName');
            $method->setAccessible(true);
            $this->assertSame(
                'string_const_690_unit_test_suffix_26411',
                $method->invoke($ctx, 'string_const_', 690)
            );
            $this->assertSame(
                'array_const_0_unit_test_suffix_26411',
                $method->invoke($ctx, 'array_const_', 0)
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
            } else {
                putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX='.$prev);
            }
        }
    }

    public function testModuleLocalConstGlobalNameDefaultsToMainSuffix(): void
    {
        $prev = getenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
        try {
            $ref = new ReflectionClass(JIT\Context::class);
            $ctx = $ref->newInstanceWithoutConstructor();
            $method = $ref->getMethod('moduleLocalConstGlobalName');
            $method->setAccessible(true);
            $this->assertSame('string_const_3_main', $method->invoke($ctx, 'string_const_', 3));
            $this->assertSame('array_const_1_main', $method->invoke($ctx, 'array_const_', 1));
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX');
            } else {
                putenv('PHP_COMPILER_INIT_SYMBOL_SUFFIX='.$prev);
            }
        }
    }

    public function testConstantStringFromStringUsesModuleLocalConstGlobalName(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Context.php');
        $this->assertMatchesRegularExpression(
            "/moduleLocalConstGlobalName\\('string_const_'/",
            $src
        );
        $this->assertMatchesRegularExpression(
            "/moduleLocalConstGlobalName\\('array_const_'/",
            $src
        );
        $this->assertStringContainsString('#26411', $src);
        $this->assertStringContainsString('_main', $src);
    }
}
