<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * examples/README.md benchmark pipeline (issue #60).
 */
final class RebuildExamplesTest extends TestCase
{
    public function testSimpleWebAotBenchmarkUsesRuntimeQueryString(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/rebuild-examples.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString("'aot_compile_time_query' => false", $script);
        $this->assertStringContainsString("'QUERY_STRING' => 'name=World'", $script);
        $this->assertStringContainsString('$profile[\'aot_compile_time_query\']', $script);
        $this->assertStringContainsString('$profile[\'aot_run_env\']', $script);
    }

    public function testRebuildExamplesDocumentsMiniWebAppBenchmarkGate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/rebuild-examples.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('shouldBenchMiniWebApp', $script);
        $this->assertStringContainsString('BENCH_MINIWEBAPP', $script);
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE', $script);
        $this->assertStringContainsString("'PATH_INFO' => '/home'", $script);
        $this->assertStringContainsString("'project_aot' => true", $script);
        $this->assertStringContainsString('tryBenchmarkMiniWebAppProjectAot', $script);
        $this->assertStringContainsString('resolveLlvmDir', $script);
        $this->assertStringContainsString("'build', '--project'", $script);
        $this->assertStringContainsString('003-MiniWebApp/public/index.php', $script);
    }

    public function testRebuildExamplesDocumentsSessionsWebBenchmarkGate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/rebuild-examples.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('shouldBenchSessionsWeb', $script);
        $this->assertStringContainsString('BENCH_SESSIONSWEB', $script);
        $this->assertStringContainsString('SESSIONSWEB_LINT_GATE', $script);
        $this->assertStringContainsString('examples/005-SessionsWeb', $script);

        $readme = file_get_contents(dirname(__DIR__, 2).'/examples/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('BENCH_SESSIONSWEB', $readme);
        $this->assertStringContainsString('005-SessionsWeb', $readme);
    }
}
