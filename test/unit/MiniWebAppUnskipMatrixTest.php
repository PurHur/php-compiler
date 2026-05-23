<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * docs/miniwebapp-aot-unskip-matrix.md and shell gate wiring (issue #676).
 */
final class MiniWebAppUnskipMatrixTest extends TestCase
{
    /** @var list<int> */
    private const MATRIX_ISSUE_IDS = [
        676,
        754,
        747,
        738,
        833,
        478,
        610,
        745,
    ];

    public function testMatrixDocListsAllIssueIds(): void
    {
        $path = dirname(__DIR__, 2).'/docs/miniwebapp-aot-unskip-matrix.md';
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);

        foreach (self::MATRIX_ISSUE_IDS as $issueId) {
            $this->assertMatchesRegularExpression(
                '/#'.preg_quote((string) $issueId, '/').'\b/',
                $body,
                "matrix doc should reference issue #{$issueId}"
            );
        }

        $this->assertStringContainsString('## How to flip', $body);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE', $body);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $body);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_ONLY=003', $body);
    }

    public function testExamplesWebSmokeReferences003AotWithoutStale568(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-web-smoke.sh');
        $this->assertStringContainsString('003-MiniWebApp', $body);
        $this->assertStringContainsString('run_miniwebapp_aot_smoke', $body);
        $this->assertStringContainsString('contact PATH_INFO|/index.php/contact', $body);
        $this->assertStringContainsString('#833', $body);
        $this->assertStringContainsString('#676', $body);
        $this->assertStringNotContainsString('#568', $body);
        $this->assertStringNotContainsString('blocked #764', $body);
    }

    public function testExamplesAotSmokeReferences003WithoutStale568(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-aot-smoke.sh');
        $this->assertStringContainsString('003-MiniWebApp', $body);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_ONLY=003', $body);
        $this->assertStringContainsString('smoke_003_miniwebapp', $body);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE', $body);
        $this->assertStringNotContainsString('#568', $body);
    }

    public function testCiDefaultsDocumentsExecuteGate(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE="${MINIWEBAPP_AOT_EXECUTE_GATE:-1}"', $body);
        $this->assertStringContainsString('#747', $body);
    }
}
