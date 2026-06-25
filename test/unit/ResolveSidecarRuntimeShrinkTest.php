<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ResolveSidecarJitHelper;
use PHPUnit\Framework\TestCase;

/** __compiler_resolve_sidecar_source_path JIT routes through ResolveSidecarJitHelper PHP (#11412). */
final class ResolveSidecarRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesResolveSidecarToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('ResolveSidecarRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitResolveSidecarSourcePath', $source);
        $this->assertStringNotContainsString("lookupFunction('access')", $source);
        $this->assertLessThan(80, \substr_count($source, "\n") + 1);
    }

    public function testResolveSidecarRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ResolveSidecarRuntime.php');
        $this->assertStringContainsString('ResolveSidecarJitHelper::resolveArgv', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString("lookupFunction('snprintf')", $source);
    }

    public function testResolveSidecarJitHelperLeavesExistingPath(): void
    {
        $path = sys_get_temp_dir().'/phpc_resolve_sidecar_'.getmypid();
        file_put_contents($path, 'ok');
        try {
            $this->assertSame($path, ResolveSidecarJitHelper::resolveArgv($path));
        } finally {
            @unlink($path);
        }
    }

    public function testResolveSidecarJitHelperUsesSidecarPathRemap(): void
    {
        $root = dirname(__DIR__, 2);
        $blob = $root.'/build/.m3_helloworld_aot_blob';
        if (!is_file($blob)) {
            $this->markTestSkipped('missing build/.m3_helloworld_aot_blob');
        }
        $prev = getenv('PHP_COMPILER_REPO_ROOT');
        putenv('PHP_COMPILER_REPO_ROOT='.$root);
        try {
            $resolved = ResolveSidecarJitHelper::resolveArgv('/compiler/build/.m3_helloworld_aot_blob');
            $this->assertSame($blob, $resolved);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_REPO_ROOT');
            } else {
                putenv('PHP_COMPILER_REPO_ROOT='.$prev);
            }
        }
    }
}
