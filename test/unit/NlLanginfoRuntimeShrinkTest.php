<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NlLanginfoJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * nl_langinfo() AOT via NlLanginfoJitHelper PHP + NestedJIT libc leaf (#30404).
 */
final class NlLanginfoRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughStringNlLanginfo(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/nl_langinfo.php');
        $this->assertStringContainsString('StringNlLanginfo::invoke', $source);
        $this->assertStringNotContainsString('JitNlLanginfo::invoke(', $source);
    }

    public function testStringNlLanginfoRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNlLanginfo.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('NlLanginfoJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitNlLanginfo::invokeLibcLeaf', $bridge);
        $this->assertStringContainsString('__compiler_nl_langinfo', $bridge);
        $this->assertStringNotContainsString("lookupFunction('nl_langinfo')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafUsesLibcNlLanginfo(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitNlLanginfo.php');
        $this->assertStringContainsString('invokeLibcLeaf', $source);
        $this->assertStringContainsString("lookupFunction('nl_langinfo')", $source);
        $this->assertStringContainsString('ensureLibcNlLanginfo', $source);
    }

    public function testModuleDropsAlwaysOnNlLanginfoDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('nl_langinfo')", $source);
        $this->assertStringContainsString('#30404', $source);
    }

    public function testNlLanginfoJitHelperUsesHostBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NlLanginfoJitHelper.php');
        $this->assertStringContainsString('\\nl_langinfo(', $source);
        $this->assertStringNotContainsString('@\\nl_langinfo', $source);
        $this->assertStringNotContainsString('VmLocale::', $source);
        $this->assertStringNotContainsString('TriggerErrorJitHelper', $source);

        if (!\defined('CODESET')) {
            $this->markTestSkipped('CODESET undefined on host');
        }
        $got = NlLanginfoJitHelper::nlLanginfoArgv((int) \CODESET);
        $this->assertIsString($got);
        $this->assertNotSame('', $got);
        $this->assertSame(\nl_langinfo((int) \CODESET), $got);
    }

    public function testNestedJitAllowlistsNlLanginfoBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'nl_langinfo'", $source);
        $this->assertStringContainsString('#30404', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesNlLanginfoArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NlLanginfoJitHelper.php', $spine);
        $this->assertStringContainsString('StringNlLanginfo.php', $spine);
        $this->assertStringContainsString('JitNlLanginfo.php', $spine);
    }
}
