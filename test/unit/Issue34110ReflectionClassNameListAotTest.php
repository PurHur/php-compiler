<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass getInterfaceNames/getTraitNames match Zend (#34110).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getInterfaceNames
 * @see \PHPCompiler\JIT\Call\ReflectionClassNameListQuery
 *
 * @group llvm
 * @group aot
 */
final class Issue34110ReflectionClassNameListAotTest extends TestCase
{
    public function testContextRegistersNameListProxies(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::getinterfacenames']",
            $source
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::gettraitnames']",
            $source
        );
        $this->assertStringContainsString('#34110', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassNameListQuery.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassNameListRuntime.php'
        );
    }

    public function testAotNameListsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34110_reflection_name_lists_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34110_nl_'.getmypid().'.bin';
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

    public function testVmNameListsRun(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34110_reflection_name_lists_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('T34110', $joined);
        $this->assertStringContainsString('J34110', $joined);
    }
}
