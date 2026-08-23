<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionMethod / ReflectionClassConstant construct must set $class/$name (#33990).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionMethod___construct
 * @see \PHPCompiler\JIT\Call\ReflectionMethodConstruct
 *
 * @group llvm
 * @group aot
 */
final class Issue33990ReflectionMethodConstructAotTest extends TestCase
{
    public function testContextRegistersConstructProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionmethod::__construct']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclassconstant::__construct']",
            $source
        );
        $this->assertStringContainsString('#33990', $source);
    }

    public function testObjectLayoutUsesValueBoxesAndHasConstructor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionmethod' === \\\$lcname.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionclassconstant' === \\\$lcname.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
    }

    public function testIsVoidJitConstructCallListsNewProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT.php'
        );
        $this->assertStringContainsString(
            'JIT\\Call\\ReflectionMethodConstruct',
            $source
        );
        $this->assertStringContainsString(
            'JIT\\Call\\ReflectionClassConstantConstruct',
            $source
        );
    }

    public function testPropertyGetAttributesUsesZendPropertyKeys(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionPropertyGetAttributes.php'
        );
        $this->assertStringContainsString('PROP_DECLARING_CLASS_NAME', $source);
        $this->assertStringContainsString('PROP_PROPERTY_NAME', $source);
        $this->assertStringNotContainsString(
            "stringPropertyAsCstr(\$context, \$obj, 'ReflectionProperty', 'property')",
            $source
        );
    }

    public function testAotReflectionMethodClassAndName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33990_reflection_method_construct_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33990_rm_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertNotSame(
                139,
                $runRc,
                "thin AOT must not SIGSEGV on ReflectionMethod::\$class (#33990)\n".$joined
            );
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame('B|m', trim($joined));
        } finally {
            @unlink($bin);
        }
    }

    public function testAotReflectionClassConstantClassAndName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33990_reflection_class_constant_construct_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33990_rcc_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertNotSame(139, $runRc, $joined);
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame('B|X', trim($joined));
        } finally {
            @unlink($bin);
        }
    }

    public function testAotReflectionPropertyGetAttributesWithAttr(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33990_reflection_property_getattributes_aot.php';
        $bin = sys_get_temp_dir().'/phpc_33990_rpga_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertNotSame(139, $runRc, $joined);
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame('1|A', trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
