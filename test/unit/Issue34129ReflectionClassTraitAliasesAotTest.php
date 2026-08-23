<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionClass::getTraitAliases match Zend (#34129).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionClass_getTraitAliases
 * @see \PHPCompiler\JIT\Call\ReflectionClassGetTraitAliases
 *
 * @group llvm
 * @group aot
 */
final class Issue34129ReflectionClassTraitAliasesAotTest extends TestCase
{
    public function testContextRegistersTraitAliasesProxy(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Context.php'
        );
        $this->assertStringContainsString(
            "functionProxies['reflectionclass::gettraitaliases']",
            $source
        );
        $this->assertStringContainsString('#34129', $source);
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Call/ReflectionClassGetTraitAliases.php'
        );
        $this->assertFileExists(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionClassTraitAliasesRuntime.php'
        );
    }

    public function testAotTraitAliasesMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34129_reflection_trait_aliases_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34129_ta_'.getmypid().'.bin';
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

    public function testVmTraitAliasesMatchZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34129_reflection_trait_aliases_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertStringContainsString('aliasT', $joined);
        $this->assertStringContainsString('T34129::t', $joined);

        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $this->assertSame(trim(implode("\n", $zendOut)), $joined);
    }
}
