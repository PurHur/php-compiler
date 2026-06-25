<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv() zero-arg native enumeration — no phpc_env_local.c / host PHP loops (#5079, #5345). */
final class GetenvNativeRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testEnvLocalCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_env_local.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_env_local.c');
    }

    public function testVmEnvUsesNativeEnvironEnumeration(): void
    {
        $vmEnv = file_get_contents($this->repoRoot.'/ext/standard/VmEnv.php');
        $this->assertIsString($vmEnv);
        $this->assertStringContainsString('VmEnvEnvironNative::enumerate()', $vmEnv);
        $this->assertMatchesRegularExpression(
            '/private static function getAllEnvironmentMap\(\): array\s*\{[^}]*VmEnvEnvironNative::enumerate\(\)/s',
            $vmEnv
        );
    }

    public function testVmEnvEnvironNativeHasNoFfi(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmEnvEnvironNative.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\FFI/', $source);
        $this->assertStringNotContainsString('libc.so', $source);
        $this->assertStringContainsString('/proc/self/environ', $source);
    }

    public function testBootstrapReproAndCompliancePresent(): void
    {
        $this->assertFileExists($this->repoRoot.'/test/repro-maintainer/bootstrap_getenv_all_native.php');
        $this->assertFileExists($this->repoRoot.'/test/compliance/cases/bootstrap/getenv_all_native.phpt');
    }

    public function testGetenvLocalOnlyUsesEnvironNotPutenvTableOnly(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmEnv.php');
        $this->assertStringContainsString('VmEnvEnvironNative::enumerate()', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/if \(\$localOnly\) \{\s*if \(!\\\\array_key_exists\(\$name, self::\$local\)\)/s',
            $source
        );
    }

    public function testGetenvLocalOnlyInheritedPathVisible(): void
    {
        \PHPCompiler\ext\standard\VmEnv::putenv('PHP_COMPILER_LOCAL_ONLY_TEST=from_putenv');
        $this->assertSame(
            'from_putenv',
            \PHPCompiler\ext\standard\VmEnv::getenv('PHP_COMPILER_LOCAL_ONLY_TEST', true)
        );
        $this->assertNotFalse(\PHPCompiler\ext\standard\VmEnv::getenv('PATH', true));
    }
}
