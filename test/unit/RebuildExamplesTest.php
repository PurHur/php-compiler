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
        $this->assertStringContainsString("'sessions_web_project_aot' => true", $script);
        $this->assertStringContainsString('tryBenchmarkSessionsWebProjectAot', $script);
        $this->assertStringContainsString('BENCH_SESSIONSWEB_AOT', $script);
        $this->assertStringContainsString('#1891', $script);
        $this->assertStringContainsString('#1973', $script);

        $readme = file_get_contents(dirname(__DIR__, 2).'/examples/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('BENCH_SESSIONSWEB', $readme);
        $this->assertStringContainsString('005-SessionsWeb', $readme);
    }

    public function testRebuildExamplesDocumentsFileUploadWebBenchmarkGate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/script/rebuild-examples.php');
        $this->assertNotFalse($script);
        $this->assertStringContainsString('shouldBenchFileUploadWeb', $script);
        $this->assertStringContainsString('BENCH_FILEUPLOADWEB', $script);
        $this->assertStringContainsString('FILEUPLOADWEB_LINT_GATE', $script);
        $this->assertStringContainsString('examples/006-FileUploadWeb', $script);
        $this->assertStringContainsString("'fileupload_web_project_aot' => true", $script);
        $this->assertStringContainsString('tryBenchmarkFileUploadWebProjectAot', $script);
        $this->assertStringContainsString('BENCH_FILEUPLOADWEB_AOT', $script);
        $this->assertStringContainsString('fileUploadWebMultipartCgiEnv', $script);
        $this->assertStringContainsString('#2027', $script);

        $readme = file_get_contents(dirname(__DIR__, 2).'/examples/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('BENCH_FILEUPLOADWEB', $readme);
        $this->assertStringContainsString('006-FileUploadWeb', $readme);
    }

    public function testCiWiresRebuildExamples005SyncGate(): void
    {
        $common = file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $defaults = file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertNotFalse($common);
        $this->assertNotFalse($defaults);
        $this->assertStringContainsString('ci_run_rebuild_examples_005_sync_check', $common);
        $this->assertStringContainsString('check-rebuild-examples-005-row.php', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE:-1', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE="${REBUILD_EXAMPLES_005_SYNC_GATE:-1}"', $defaults);
    }
}
