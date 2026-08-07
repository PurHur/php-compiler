<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GethostnameJitHelper;
use PHPUnit\Framework\TestCase;

/** gethostname() JIT: VmHostPure SSOT + /proc NestedJIT leaf — no libc gethostname(2) (#21166, #28544). */
final class GethostnameRuntimeShrinkTest extends TestCase
{
    public function testJitGethostnameUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGethostname.php');
        $this->assertStringContainsString('StringGethostname::invoke', $source);
        $this->assertStringContainsString('JitGetcwd::boxed', $source);
        $this->assertStringNotContainsString("lookupFunction('gethostname')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
        $this->assertStringNotContainsString('gethostname_buf', $source);
        $this->assertStringNotContainsString('__string__init', $source);
    }

    public function testStringGethostnameAlwaysUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGethostname.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('GethostnameJitHelper', $source);
        $this->assertStringContainsString('JitGethostnameKernel::invoke', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString("lookupFunction('gethostname')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testKernelUsesProcHostnameNotLibcGethostname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGethostnameKernel.php');
        $this->assertStringContainsString('/proc/sys/kernel/hostname', $source);
        $this->assertStringContainsString('/etc/hostname', $source);
        $this->assertStringContainsString("lookupFunction('open')", $source);
        $this->assertStringContainsString("lookupFunction('read')", $source);
        $this->assertStringNotContainsString("lookupFunction('gethostname')", $source);
        $this->assertStringContainsString('VmHostPure', $source);
    }

    public function testKernelExecuteUsesVmHostPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/phpc_gethostname_kernel.php');
        $this->assertStringContainsString('VmHostPure::gethostname', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\gethostname\\s*\\(/', $source);
    }

    public function testLibcExternNoLongerDeclaresGethostname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'gethostname' =>", $source);
    }

    public function testGethostnameJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GethostnameJitHelper.php');
        $this->assertStringContainsString('public static function resolveJit(): string', $source);
        $this->assertStringContainsString('phpc_gethostname_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/return\s+\\\\is_string\s*\(\s*\$host\s*\)\s*\?\s*\$host\s*:\s*[\'"][\'"]/',
            $source
        );
        $this->assertStringNotContainsString('@\\gethostname(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$host\s*=\s*VmHost::/m',
            $source
        );

        if (!\function_exists('phpc_gethostname_kernel')) {
            $this->markTestSkipped('phpc_gethostname_kernel requires compiler runtime');
        }
        if (!\function_exists('gethostname')) {
            $this->markTestSkipped('host gethostname unavailable');
        }
        $expected = \gethostname();
        $this->assertNotFalse($expected);
        $this->assertSame($expected, GethostnameJitHelper::resolveJit());
    }

    public function testModuleNoLongerDeclaresLibcGethostname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('phpc_gethostname_kernel', $source);
        $this->assertStringNotContainsString("lookupFunction('gethostname')", $source);
        $this->assertStringNotContainsString("addFunction('gethostname'", $source);
    }

    public function testSpineBundleIncludesGethostnameHelperAndKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GethostnameJitHelper.php', $spine);
        $this->assertStringContainsString('StringGethostname.php', $spine);
        $this->assertStringContainsString('JitGethostnameKernel.php', $spine);
        $this->assertStringContainsString('phpc_gethostname_kernel.php', $spine);
    }
}
