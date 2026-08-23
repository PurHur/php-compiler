<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass::newInstanceWithoutConstructor matches Zend (#34078).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_newInstanceWithoutConstructor
 * @see \PHPCompiler\JIT\Call\ReflectionClassNewInstanceWithoutConstructor
 *
 * @group llvm
 * @group aot
 */
final class Issue34078ReflectionClassNewInstanceWithoutCtorAotTest extends TestCase
{
    private const EXPECT = "Foo:1\nError:Cannot instantiate abstract class Abs";

    public function testContextRegistersNewInstanceWithoutConstructorProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::newinstancewithoutconstructor']",
            $source
        );
        $this->assertStringContainsString('#34078', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassNewInstanceWithoutConstructor.php'
        );
        $guard = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/InstantiableClassJitGuard.php'
        );
        $this->assertStringContainsString('isAbstractClassLc', $guard);
        $this->assertStringContainsString('#34078', $guard);
    }

    public function testAotNewInstanceWithoutConstructorMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34078_reflection_new_instance_without_ctor_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34078_ni_'.getmypid().'.bin';
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

    public function testVmNewInstanceWithoutConstructorMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34078_reflection_new_instance_without_ctor_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = implode("\n", $out);
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, trim($joined));
    }
}
