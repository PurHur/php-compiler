<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Definition of Done close-scope gate (#36400).
 */
final class IssueCloseScopeTest extends TestCase
{
    public function testSelfTestRejectsBareClosesAndAcceptsTicks(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/check-issue-close-scope.php')
            .' --self-test 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('self-test OK', implode("\n", $out));
    }

    public function testContributingDocumentsDefinitionOfDone(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/CONTRIBUTING.md');
        $this->assertStringContainsString('Definition of Done', $body);
        $this->assertStringContainsString('check-issue-close-scope.php', $body);
        $this->assertStringContainsString('Part of #N', $body);
    }

    public function testPullRequestTemplateExists(): void
    {
        $path = dirname(__DIR__, 2).'/.github/PULL_REQUEST_TEMPLATE.md';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('Done when', $body);
        $this->assertStringContainsString('Part of', $body);
        $this->assertStringContainsString('#36400', $body);
    }

    public function testCiRunsCloseScopeSelfTest(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('check-issue-close-scope.php', $common);
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ISSUE_CLOSE_SCOPE_GATE', $defaults);
    }
}
