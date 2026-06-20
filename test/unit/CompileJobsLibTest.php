<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class CompileJobsLibTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_COMPILE_JOBS');
        parent::tearDown();
    }

    public function testCompileJobsDefaultsToOne(): void
    {
        putenv('PHP_COMPILER_COMPILE_JOBS');
        require_once dirname(__DIR__, 2).'/script/compile-jobs-lib.php';

        $this->assertSame(1, php_compiler_compile_jobs());
    }

    public function testCompileJobsRespectsEnvAndCaps(): void
    {
        require_once dirname(__DIR__, 2).'/script/compile-jobs-lib.php';

        putenv('PHP_COMPILER_COMPILE_JOBS=4');
        $this->assertSame(4, php_compiler_compile_jobs());

        putenv('PHP_COMPILER_COMPILE_JOBS=0');
        $this->assertSame(1, php_compiler_compile_jobs());

        putenv('PHP_COMPILER_COMPILE_JOBS=999');
        $this->assertSame(php_compiler_compile_jobs_cap(), php_compiler_compile_jobs());
    }

    public function testParallelCommandsRunAllTasks(): void
    {
        require_once dirname(__DIR__, 2).'/script/compile-jobs-lib.php';

        $tasks = [
            ['id' => 'a', 'cmd' => 'printf a'],
            ['id' => 'b', 'cmd' => 'printf b'],
        ];
        $results = php_compiler_run_parallel_commands($tasks, 2);
        $this->assertSame(0, $results['a']['exit']);
        $this->assertSame(0, $results['b']['exit']);
        $this->assertStringContainsString('a', $results['a']['output']);
        $this->assertStringContainsString('b', $results['b']['output']);
    }

    public function testVendorPrelinkLibReferencesCompileJobs(): void
    {
        $lib = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-vendor-prelink-lib.php');
        $this->assertStringContainsString('PHP_COMPILER_COMPILE_JOBS', $lib);
        $this->assertStringContainsString('php_compiler_run_parallel_commands', $lib);
    }

    public function testSidecarWarmLibReferencesCompileJobs(): void
    {
        $lib = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-m3-sidecar-warm-lib.php');
        $this->assertStringContainsString('PHP_COMPILER_COMPILE_JOBS', $lib);
        $this->assertStringContainsString('bootstrapM3SidecarWarmRun', $lib);
    }
}
