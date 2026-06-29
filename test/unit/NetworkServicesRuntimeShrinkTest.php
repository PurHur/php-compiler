<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Network service lookups C runtime shrink (#6218, #5333, #9777). */
final class NetworkServicesRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcNetworkServicesCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_network_services.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_network_services.c');
    }

    public function testStringReturnUsesNetworkServicesJitHelper(): void
    {
        $stringReturn = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServicesStringReturn.php');
        $this->assertStringContainsString('NetworkServicesJitHelper', $stringReturn);
        $this->assertStringContainsString('buildJitTables', $stringReturn);
        $this->assertStringContainsString('emitGetprotobynumberBody', $stringReturn);
        $this->assertStringNotContainsString('ns_proto_num_match_', $stringReturn);
    }

    public function testNameLookupUsesNetworkServicesNameLookupJitHelper(): void
    {
        $nameLookup = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServicesNameLookup.php');
        $this->assertStringContainsString('NetworkServicesNameLookupJitHelper', $nameLookup);
        $this->assertStringContainsString('getprotobynameLookup', $nameLookup);
        $this->assertStringContainsString('getservbynameLookup', $nameLookup);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServicesJit.php');
        $this->assertFileExists($this->repoRoot.'/ext/standard/NetworkServicesNameLookupJitHelper.php');
    }

    public function testNameLookupJitShrunkToPhpBridge(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServicesNameLookup.php');
        $this->assertStringNotContainsString('strcasecmp', $source);
        $this->assertStringNotContainsString('buildJitTables', $source);
        $this->assertStringNotContainsString('ns_proto_name_match_', $source);
    }

    public function testDeadVmNetworkHostDelegationRemoved(): void
    {
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/VmNetwork.php');
    }

    public function testVmNetworkServicesDoesNotDelegateConfigReadsToHostFile(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmNetworkServices.php');
        $this->assertStringContainsString('VmFs::file', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file\\s*\\(/', $source);
        $this->assertStringContainsString('buildJitTables', $source);
    }
}
