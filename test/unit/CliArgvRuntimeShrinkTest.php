<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CliArgvRuntime: honest refresh via __hashtable__alloc — no void thin stub (#9439, #20904).
 */
final class CliArgvRuntimeShrinkTest extends TestCase
{
    public function testCliArgvRuntimeUsesHonestRefreshNotVoidStub(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('__hashtable__alloc', $source);
        $this->assertStringContainsString('implementRefreshArgvGlobal', $source);
        $this->assertStringContainsString('VmCliArgv', (string) file_get_contents(__DIR__.'/../../ext/standard/VmCliArgv.php'));
        $this->assertStringNotContainsString('CliArgvStandaloneLlvm', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('ensureUserScriptMainStubs', $source);
        $this->assertStringNotContainsString('cli_refresh_stub', $source);
        $this->assertStringNotContainsString('CREATE_TABLE_HELPER', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/CliArgvStandaloneLlvm.php');
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CliArgvRuntime.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('self::implement($context)', $source);
        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('CliArgvRuntime::ensureStandaloneBodies', $ctx);
        $this->assertStringNotContainsString('CliArgvRuntime::ensureUserScriptMainStubs', $ctx);
        $init = (string) file_get_contents(__DIR__.'/../../lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString('CliArgvRuntime::ensureLinked', $init);
    }

    public function testVmCliArgvBuildsIndexedArgvTable(): void
    {
        $ht = \PHPCompiler\ext\standard\VmCliArgv::buildArgvTable(['script.php', 'foo']);
        $this->assertSame(2, $ht->getNumElements());
        $this->assertSame('script.php', $ht->findIndex(0)?->toString());
        $this->assertSame('foo', $ht->findIndex(1)?->toString());
    }

    public function testSuperglobalsPopulateCliArgvUsesVmCliArgv(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Web/Superglobals.php');
        $this->assertStringContainsString('VmCliArgv::buildArgvTable', $source);
    }

    public function testSpineBundleIncludesCliArgvPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CliArgvRuntime.php', $spine);
        $this->assertStringContainsString('VmCliArgv.php', $spine);
    }
}
