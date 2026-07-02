<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LogJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log() JIT routes through LogJitHelper PHP not libc LLVM (#15117). */
final class LogRuntimeShrinkTest extends TestCase
{
    public function testLogUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log.php');
        $this->assertStringContainsString('MathLog::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog.php');
        $this->assertStringContainsString('LogJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log', $bridge);
    }

    public function testLogJitHelperDelegatesToVmMath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LogJitHelper.php');
        $this->assertStringContainsString('VmMath::log', $source);

        $this->assertSame(
            VmMath::log(1.0),
            LogJitHelper::logArgv(1.0)
        );
        $this->assertSame(
            VmMath::log(\M_E),
            LogJitHelper::logArgv(\M_E)
        );
    }

    public function testSpineBundleIncludesLogJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LogJitHelper.php', $spine);
        $this->assertStringContainsString('MathLog.php', $spine);
    }
}
