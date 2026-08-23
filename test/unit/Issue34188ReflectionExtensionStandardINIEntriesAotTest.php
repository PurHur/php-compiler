<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: ReflectionExtension('standard')->getINIEntries matches Zend (#34188).
 *
 * @see php-src ext/reflection/php_reflection.c zim_ReflectionExtension_getINIEntries
 * @see \PHPCompiler\JIT\Builtin\ReflectionExtensionGetINIEntriesRuntime
 *
 * @group llvm
 * @group aot
 */
final class Issue34188ReflectionExtensionStandardINIEntriesAotTest extends TestCase
{
    private const EXPECT = <<<'TXT'
count=14
assert.active=1
assert.callback_null=1
from_null=1
user_agent_null=1
default_socket_timeout=1
TXT;

    public function testRuntimeBakesStandardWithNullLocals(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/ReflectionExtensionGetINIEntriesRuntime.php'
        );
        $this->assertStringContainsString("'standard'", $source);
        $this->assertStringContainsString('#34188', $source);
        $this->assertStringContainsString('Keep null locals', $source);
    }

    public function testAotStandardINIEntriesMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34188_reflection_extension_standard_inientries_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34188_ini_'.getmypid().'.bin';
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

    public function testZendStandardINIEntriesBaseline(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34188_reflection_extension_standard_inientries_aot.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $joined = trim(implode("\n", $out));
        $this->assertSame(0, $rc, $joined);
        $this->assertSame(self::EXPECT, $joined);
    }

    public function testDateINIEntriesStillGreen(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34165_reflection_extension_getinientries_aot.php';
        $bin = sys_get_temp_dir().'/phpc_34188_date_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $joined = trim(implode("\n", $runOut));
            $this->assertSame(0, $runRc, $joined);
            $this->assertSame(
                'type=array count=5 date.timezone=1 date.default_latitude=1',
                $joined
            );
        } finally {
            @unlink($bin);
        }
    }
}
