<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass::__toString matches Zend (#34135).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass___toString
 * @see \PHPCompiler\JIT\Call\ReflectionClassToString
 *
 * @group llvm
 * @group aot
 */
final class Issue34135ReflectionClassToStringAotTest extends TestCase
{
    public function testContextRegistersToStringProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::__tostring']",
            $source
        );
        $this->assertStringContainsString('#34135', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassToString.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassToStringRuntime.php'
        );
    }

    public function testAotToStringMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34135_reflection_class_tostring_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34135_ts_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expect = implode("\n", $zendOut);

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = implode("\n", $runOut);
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame($expect, $joined);
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmToStringRuns(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34135_reflection_class_tostring_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('C34135', $joined);
        $this->assertStringContainsString('stdClass', $joined);
        $this->assertStringContainsString('Class [', $joined);
    }
}
