<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ProcNiceJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * proc_nice() JIT: ProcNiceJitHelper + StringProcNice NestedJIT leaf (#30615, #5181).
 */
final class ProcNiceRuntimeShrinkTest extends TestCase
{
    public function testJitProcNiceUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitProcNice.php');
        $this->assertStringContainsString('StringProcNice::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('nice')", $source);
        $this->assertStringNotContainsString('ensureLibcNice', $source);
        $this->assertStringNotContainsString('__errno_location', $source);
        $this->assertStringNotContainsString('JitValueBox', $source);
    }

    public function testStringProcNiceUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringProcNice.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('ProcNiceJitHelper', $source);
        $this->assertStringContainsString('__compiler_nice', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
    }

    public function testProcNiceJitHelperUsesHostProcNiceNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ProcNiceJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(int $priority): int', $source);
        $this->assertStringContainsString('@\\proc_nice', $source);
        $this->assertStringNotContainsString('VmProcNicePure::', $source);
        $this->assertStringNotContainsString('VmProcNiceNative::', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);

        if (!\function_exists('proc_nice')) {
            $this->markTestSkipped('host proc_nice unavailable');
        }
        $this->assertSame(1, ProcNiceJitHelper::invokeArgv(0));
    }

    public function testModuleCommentPointsAtStringProcNice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('nice')", $source);
        $this->assertStringNotContainsString("addFunction('nice'", $source);
        $this->assertStringContainsString('#30615', $source);
        $this->assertStringContainsString('StringProcNice', $source);
    }

    public function testSpineBundleIncludesProcNiceHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ProcNiceJitHelper.php', $spine);
        $this->assertStringContainsString('StringProcNice.php', $spine);
        $this->assertStringContainsString('JitProcNice.php', $spine);
    }

    public function testContextWhitelistsProcNice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'proc_nice'", $source);
        $this->assertStringContainsString('#30615', $source);
    }

    public function testProcNiceBuiltinRoutesThroughJitProcNice(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/proc_nice.php');
        $this->assertStringContainsString('JitProcNice::invoke', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringNotContainsString('JitValueBox::alloc', $source);
    }
}
