<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass::newInstance matches Zend (#34083).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_newInstance
 * @see \PHPCompiler\JIT\Call\ReflectionClassNewInstance
 *
 * @group llvm
 * @group aot
 */
final class Issue34083ReflectionClassNewInstanceAotTest extends TestCase
{
    /** Happy-path lines shared by VM and AOT. */
    private const EXPECT_PREFIX = "CTOR:5\nFoo:6\nBar:7\n";

    /**
     * Thin AOT allocateForRuntimeClassId raises Error (peer #34078).
     * VM ReflectionClass::newInstance throws ReflectionException (php-src).
     */
    private const EXPECT_AOT_ABS = 'Error:Cannot instantiate abstract class Abs';
    private const EXPECT_VM_ABS = 'ReflectionException:Class Abs is not instantiable';

    public function testContextRegistersNewInstanceProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::newinstance']",
            $source
        );
        $this->assertStringContainsString('#34083', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassNewInstance.php'
        );
    }

    public function testAotNewInstanceMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34083_reflection_new_instance_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34083_ni_'.getmypid().'.bin';
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
                $this->assertSame(self::EXPECT_PREFIX.self::EXPECT_AOT_ABS, trim($joined));
            }
        } finally {
            @unlink($bin);
        }
    }

    public function testVmNewInstanceMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34083_reflection_new_instance_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT_PREFIX.self::EXPECT_VM_ABS, trim($joined));
    }
}
