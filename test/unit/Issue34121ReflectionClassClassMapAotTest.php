<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass getInterfaces/getTraits match Zend (#34121).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getInterfaces
 * @see \PHPCompiler\JIT\Call\ReflectionClassClassMapQuery
 *
 * @group llvm
 * @group aot
 */
final class Issue34121ReflectionClassClassMapAotTest extends TestCase
{
    public function testContextRegistersClassMapProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getinterfaces']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::gettraits']",
            $source
        );
        $this->assertStringContainsString('#34121', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassClassMapQuery.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassClassMapRuntime.php'
        );
    }

    public function testAotClassMapsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34121_reflection_class_maps_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34121_cm_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expect = trim(implode("\n", $zendOut));

        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $joined = trim(implode("\n", $runOut));
                $this->assertSame(0, $runRc, 'run '.$i.': '.$joined);
                $this->assertSame($expect, $joined);
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmClassMapsRun(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34121_reflection_class_maps_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('J34121', $joined);
        $this->assertStringContainsString('T34121', $joined);
        $this->assertStringContainsString('array', $joined);
    }
}
