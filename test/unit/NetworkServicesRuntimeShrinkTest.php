<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Network service lookups C runtime shrink (#6218, #5333). */
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

    public function testJitLoweringUsesPhpNetworkServicesOnly(): void
    {
        $jit = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServicesJit.php');
        $this->assertIsString($jit);
        $this->assertStringContainsString('VmNetworkServices', $jit);
        $this->assertStringNotContainsString('phpc_network_services', $jit);

        $runtime = file_get_contents($this->repoRoot.'/lib/JIT/Builtin/StringNetworkServices.php');
        $this->assertIsString($runtime);
        $this->assertStringContainsString('StringNetworkServicesJit', $runtime);
        $this->assertStringContainsString('Replaces lib/AOT/runtime/phpc_network_services.c', $runtime);
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
    }
}
