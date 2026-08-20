<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChownJitHelper;
use PHPUnit\Framework\TestCase;

/** chown()/chgrp() JIT: ChownJitHelper via JitVmHelperLink (#9585, #24473, #32466). */
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

    public function testChownRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php');
        $this->assertStringContainsString('ChownJitHelper::chownArgv', $source);
        $this->assertStringContainsString('ChownJitHelper::chgrpArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('__value__readLong', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('chown')", $source);
        $this->assertLessThan(210, \substr_count($source, "\n") + 1);
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
