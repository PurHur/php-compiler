<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension::getINIEntries for filter/openssl/mbstring/session (#34193).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getINIEntries
 * @see \PHPCompiler\JIT\Builtin\ReflectionExtensionGetINIEntriesRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34193ReflectionExtensionGetINIEntriesModulesAotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
filter count=2 keys=filter.default,filter.default_flags
openssl count=2 keys=openssl.cafile,openssl.capath
mbstring count=11 internal_encoding=1
session count=30 name=1
TXT;

    public function testRuntimeBakesSmallExtensionModules(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionGetINIEntriesRuntime.php'
        );
        foreach (['filter', 'openssl', 'mbstring', 'session'] as $ext) {
            $this->assertStringContainsString("'".$ext."'", $source);
        }
        $this->assertStringContainsString('#34193', $source);
    }

    public function testZendGetINIEntriesModulesBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34193_reflection_extension_getinientries_modules_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testVmGetINIEntriesModulesMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34193_reflection_extension_getinientries_modules_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/vm.php').' '
            .escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testAotGetINIEntriesModulesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34193_reflection_extension_getinientries_modules_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34193_gen_'.getmypid().'.bin';
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

    public function testStandardReproStillMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34188_reflection_extension_getinientries_standard_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34193_std_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expect = 'type=array count=14 assert.active=1 user_agent_null=1 default_socket_timeout=1';
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($expect, trim(implode("\n", $runOut)));
        } finally {
            @unlink($bin);
        }
    }
}
