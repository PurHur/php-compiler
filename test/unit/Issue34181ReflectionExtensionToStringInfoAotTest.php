<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::__toString / info match VM (#34181).
 *
 * @see php-src ext/reflection/php_reflection.c _extension_string / php_info_print_module
 * @see \PHPCompiler\JIT\Call\ReflectionExtensionToString
 * @see \PHPCompiler\JIT\Call\ReflectionExtensionInfo
 *
 * @group llvm
 * @group aot
 */
final class Issue34181ReflectionExtensionToStringInfoAotTest extends TestCase
{
    private const EXPECT = "cast_ok=1\ninfo_len=32 support=1";

    public function testContextRegistersToStringAndInfoProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionextension::__tostring']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionextension::info']",
            $source
        );
        $this->assertStringContainsString('#34181', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionExtensionToString.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionExtensionInfo.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionToStringRuntime.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionInfoRuntime.php'
        );
    }

    public function testAotToStringAndInfoMatchVm(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34181_reflection_extension_tostring_info_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34181_'.getmypid().'.bin';
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
                $joined = trim(implode("\n", $runOut));
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame(self::EXPECT, $joined);
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmToStringAndInfoBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34181_reflection_extension_tostring_info_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }
}
