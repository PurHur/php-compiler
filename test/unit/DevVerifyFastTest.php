<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** dev-verify-fast + bootstrap trust preflight (#36145). */
final class DevVerifyFastTest extends TestCase
{
    public function testDevVerifyFastScriptIncludesBootstrapTrustPreflight(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/dev-verify-fast.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-trust-preflight.sh', $body);
        $this->assertStringContainsString('tier 0', $body);
        $this->assertStringContainsString('bootstrap-gen0-driver-functional-smoke', $body);
        $this->assertStringContainsString('tier 0f', $body);
        $this->assertStringContainsString('non-blocking', $body);
    }

    public function testBootstrapTrustPreflightScriptExistsAndRuns(): void
    {
        $root = dirname(__DIR__, 2);
        $script = $root.'/script/bootstrap-trust-preflight.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('bootstrap-gen0-staleness.php', $body);
        $this->assertStringContainsString('check-bootstrap-gen0-manifest-sync.php', $body);
        $this->assertStringContainsString('BOOTSTRAP_TRUST_PREFLIGHT_STRICT', $body);
        $this->assertStringContainsString('#36145', $body);

        exec('bash '.escapeshellarg($script).' 2>&1', $lines, $code);
        $joined = implode("\n", $lines);
        $this->assertSame(0, $code, $joined);
        $this->assertMatchesRegularExpression('/bootstrap-trust-preflight: (OK|WARNING|continuing)/', $joined);
    }

    public function testMakefileDeclaresBootstrapTrustPreflightTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('bootstrap-trust-preflight:', $makefile);
        $this->assertStringContainsString('bootstrap-trust-preflight.sh', $makefile);
    }
}
