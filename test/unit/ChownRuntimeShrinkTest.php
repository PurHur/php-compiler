<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChownJitHelper;
use PHPUnit\Framework\TestCase;

/** chown()/chgrp() JIT: ChownLibcRuntime libc (#9585, #32466). ChownJitHelper kept for int ABI tests. */
final class ChownRuntimeShrinkTest extends TestCase
{
    public function testStringFsDirJitDelegatesChownChgrpToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('ChownRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitChown', $source);
        $this->assertStringNotContainsString('emitChgrp', $source);
        $this->assertStringNotContainsString('resolveIdFromValue', $source);
    }

    public function testChownRuntimeUsesChownLibcRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php');
        $this->assertStringContainsString('ChownLibcRuntime::emitChown', $source);
        $this->assertStringContainsString('ChownLibcRuntime::emitChgrp', $source);
        $this->assertStringNotContainsString('ChownJitHelper::chownArgv', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertLessThan(130, \substr_count($source, "\n") + 1);
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownLibcRuntime.php');
        $this->assertStringContainsString("lookupFunction('chown')", $libc);
        $this->assertStringContainsString("lookupFunction('fchownat')", $libc);
    }

    public function testChownJitHelperMatchesLibcIntUid(): void
    {
        $path = sys_get_temp_dir().'/phpc_chown_jit_'.getmypid();
        file_put_contents($path, 'x');
        $st = stat($path);
        $uid = (int) ($st['uid'] ?? 0);
        $this->assertSame(1, ChownJitHelper::chownArgv($path, $uid, 0));
        @unlink($path);
    }

    public function testChgrpJitHelperMatchesLibcIntGid(): void
    {
        $path = sys_get_temp_dir().'/phpc_chgrp_jit_'.getmypid();
        file_put_contents($path, 'x');
        $st = stat($path);
        $gid = (int) ($st['gid'] ?? 0);
        $this->assertSame(1, ChownJitHelper::chgrpArgv($path, $gid, 0));
        @unlink($path);
    }

    public function testChownJitHelperUsesAtChownNotVmFs(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChownJitHelper.php');
        $this->assertStringContainsString('@\\chown', $source);
        $this->assertStringContainsString('@\\chgrp', $source);
        $this->assertStringNotContainsString('VmFs::chown', $source);
        $this->assertStringNotContainsString('Variable $user', $source);
        $this->assertStringContainsString('int $uid', $source);
        $this->assertStringContainsString('int $gid', $source);
    }
}
