<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass / ReflectionObject construct must set readable $name (#34001).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass___construct
 * @see \PHPCompiler\JIT\Call\ReflectionClassConstruct
 *
 * @group llvm
 * @group aot
 */
final class Issue34001ReflectionClassObjectNameAotTest extends TestCase
{
    public function testContextRegistersObjectGetNameProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionobject::getname']",
            $source
        );
        $this->assertStringContainsString('#34001', $source);
    }

    public function testObjectLayoutUsesValueBoxesAndHasConstructor(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/Object_.php'
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionclass' === \\\$lcname.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'reflectionobject' === \\\$lcname.*?TYPE_VALUE.*?markHasConstructor/s",
            $source
        );
    }

    public function testNewPathWiresReflectionObjectConstruct(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT.php'
        );
        $this->assertStringContainsString(
            "strcasecmp(ltrim(\$classOp->value, '\\\\'), 'ReflectionObject')",
            $source
        );
        $this->assertStringContainsString('reflectionobject::__construct', $source);
    }

    public function testAotReflectionClassNameAndGetName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34001_reflection_class_name_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34001_rc_'.getmypid().'.bin';
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
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame("A\nA", trim($joined));
        } finally {
            @unlink($bin);
        }
    }

    public function testAotReflectionObjectNameAndGetName(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34001_reflection_object_name_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34001_ro_'.getmypid().'.bin';
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
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame("A\nA", trim($joined));
        } finally {
            @unlink($bin);
        }
    }
}
