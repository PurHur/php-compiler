<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Closed-issue blocker copy guard (issue #802).
 */
final class StaleIssueRefsTest extends TestCase
{
    public function testCheckStaleIssueRefsScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-stale-issue-refs.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('CLOSED_ISSUES=(568 67 764)', $body);
        $this->assertStringContainsString('stale-issue-ok:', $body);
    }

    public function testCheckStaleIssueRefsPassesInRepo(): void
    {
        $script = dirname(__DIR__, 2).'/script/check-stale-issue-refs.sh';
        exec('bash '.escapeshellarg($script).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsStaleIssueRefsCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('check-stale-issue-refs.sh', $common);
    }
}
