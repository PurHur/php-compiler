<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChownJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** chown()/chgrp() JIT: ChownJitHelper via JitVmHelperLink (#9585, #24473). */
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
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString("lookupFunction('chown')", $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testChownJitHelperMatchesVmFs(): void
    {
        $path = sys_get_temp_dir().'/phpc_chown_jit_'.getmypid();
        file_put_contents($path, 'x');
        $st = stat($path);
        $uid = (int) ($st['uid'] ?? 0);
        $user = new Variable();
        $user->int($uid);
        $this->assertSame(
            VmFs::chown($path, $user) ? 1 : 0,
            ChownJitHelper::chownArgv($path, $user, 0)
        );
        @unlink($path);
    }

    public function testChgrpJitHelperMatchesVmFs(): void
    {
        $path = sys_get_temp_dir().'/phpc_chgrp_jit_'.getmypid();
        file_put_contents($path, 'x');
        $st = stat($path);
        $gid = (int) ($st['gid'] ?? 0);
        $group = new Variable();
        $group->int($gid);
        $this->assertSame(
            VmFs::chgrp($path, $group) ? 1 : 0,
            ChownJitHelper::chgrpArgv($path, $group, 0)
        );
        @unlink($path);
    }
}
