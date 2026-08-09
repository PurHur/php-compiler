<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT runtime surface inventory — lib/AOT/runtime/ and lib/JIT/Builtin/*.c shrink guard (#5211, #1492).
 */
final class AotRuntimeInventoryTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testAotRuntimeDirectoryContainsNoCRuntimeSources(): void
    {
        $runtimeDir = $this->repoRoot.'/lib/AOT/runtime';
        $this->assertDirectoryExists($runtimeDir);

        $cFiles = glob($runtimeDir.'/*.c') ?: [];
        sort($cFiles);
        $this->assertSame([], $cFiles, 'lib/AOT/runtime/ must contain no hand-written C runtime TUs');
    }

    public function testLinkerRuntimeCSourcesEmpty(): void
    {
        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        if (!preg_match('/private const RUNTIME_C_SOURCES = \[(.*?)\];/s', $linker, $m)) {
            $this->fail('Linker::RUNTIME_C_SOURCES not found');
        }
        $block = $m[1];
        $this->assertStringNotContainsString('.c', $block, 'Linker::RUNTIME_C_SOURCES must remain empty');
    }

    /** Bundled LLVM sysroot may ship stdio.h without stddef.h — layer host -isystem on sysroot (#1492). */
    public function testLinkerRuntimeCIncludeFlagsLayerHostOnSysroot(): void
    {
        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertStringContainsString('runtimeCHostLibcIncludeFlags()', $linker);
        $this->assertStringContainsString('$hostFlags = self::runtimeCHostLibcIncludeFlags()', $linker);
        $this->assertStringContainsString("return \$flags.\$hostFlags", $linker);
        $this->assertStringContainsString('runtimeCSysrootGccIncludeFlags(', $linker);
        $this->assertStringContainsString("'/usr/lib/gcc/*/*/include'", $linker);
    }

    public function testNoJitBuiltinCRuntimeSources(): void
    {
        $jitDir = $this->repoRoot.'/lib/JIT/Builtin';
        $this->assertDirectoryExists($jitDir);

        $cFiles = glob($jitDir.'/*.c') ?: [];
        $this->assertSame([], $cFiles, 'lib/JIT/Builtin/ must not ship hand-written C runtime TUs');
    }

    /** Issue #5211 / #6110: microtime()/gettimeofday() LLVM from PHP, not phpc_microtime.c / phpc_gettimeofday.c. */
    public function testMicrotimeGettimeofdayCRuntimeDeleted(): void
    {
        $runtimeDir = $this->repoRoot.'/lib/AOT/runtime';
        $this->assertFileDoesNotExist($runtimeDir.'/phpc_microtime.c');
        $this->assertFileDoesNotExist($runtimeDir.'/phpc_gettimeofday.c');

        $linker = (string) file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertStringNotContainsString('phpc_microtime', $linker);
        $this->assertStringNotContainsString('phpc_gettimeofday', $linker);

        $microtime = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('__compiler_microtime_string', $microtime);
        $this->assertStringContainsString('__compiler_microtime_float', $microtime);
        $this->assertStringContainsString('MicrotimeJitHelper', $microtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $microtime);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $microtime);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $microtime);

        $gettimeofday = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringGettimeofday.php');
        $this->assertStringContainsString('__compiler_gettimeofday', $gettimeofday);
        $this->assertStringContainsString('GettimeofdayJitHelper', $gettimeofday);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $gettimeofday);
    }
}
