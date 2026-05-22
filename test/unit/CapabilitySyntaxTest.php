<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Language-construct capability matrix (issue #611).
 */
final class CapabilitySyntaxTest extends TestCase
{
    public function testCapabilitySyntaxScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/capability-syntax.php';
        $this->assertFileExists($script);
    }

    public function testCapabilitiesSyntaxDocExistsWithTrackedIssues(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/capabilities-syntax.md';
        $this->assertFileExists($doc);
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('#58', $body);
        $this->assertStringContainsString('#568', $body);
        $this->assertStringContainsString('#199', $body);
        $this->assertStringContainsString('Native user-class link', $body);
        $this->assertStringContainsString('Magic constants', $body);
        $this->assertStringContainsString('| Construct | VM | JIT | AOT | Issue | Notes |', $body);
    }

    public function testCapabilitiesMdLinksToSyntaxMatrix(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/capabilities.md';
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('capabilities-syntax.md', $body);
        $this->assertStringContainsString('capability-syntax.php', $body);
    }

    public function testCiInventoryRunsCapabilitySyntaxCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('capability-syntax.php --check', $common);
    }
}
