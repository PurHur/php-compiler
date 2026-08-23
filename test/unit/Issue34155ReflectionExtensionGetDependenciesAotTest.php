<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::getDependencies matches Zend/VM (#34155).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getDependencies
 * @see \PHPCompiler\JIT\Call\ReflectionExtensionGetDependencies
 *
 * @group llvm
 * @group aot
 */
final class Issue34155ReflectionExtensionGetDependenciesAotTest extends TestCase
{
    private const EXPECT = 'date_type=array date_count=0 dom_type=array dom_count=2 dom_libxml=Required';

    public function testContextRegistersGetDependenciesProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionextension::getdependencies']",
            $source
        );
        $this->assertStringContainsString('#34155', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionExtensionGetDependencies.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionGetDependenciesRuntime.php'
        );
    }

    public function testAotGetDependenciesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34155_reflection_extension_getdependencies_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34155_gen_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testZendGetDependenciesBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34155_reflection_extension_getdependencies_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmGetDependenciesMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34155_reflection_extension_getdependencies_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }
}
