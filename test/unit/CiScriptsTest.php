<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Tiered local CI scripts (issue #436).
 */
final class CiScriptsTest extends TestCase
{
    public function testCiFastScriptExistsAndExcludesLlvmGroup(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $fast = $repoRoot.'/script/ci-fast.sh';
        $this->assertFileExists($fast);
        $this->assertTrue(is_executable($fast));
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('--exclude-group llvm', $body);
        $this->assertStringContainsString('ci-local.sh', $body);
    }

    public function testCiLocalRunsPhasedLlvmGroups(): void
    {
        $local = dirname(__DIR__, 2).'/script/ci-local.sh';
        $body = (string) file_get_contents($local);
        $this->assertStringContainsString('--group aot-lint', $body);
        $this->assertStringContainsString('--group jit', $body);
        $this->assertStringContainsString('--group aot-link', $body);
        $this->assertStringContainsString('ci_prepare_test_runtime', $body);
        $this->assertStringContainsString('ci_run_examples_web_smoke_aot', $body);
    }

    public function testExamplesWebSmokePrebuildScriptExists(): void
    {
        $prebuild = dirname(__DIR__, 2).'/script/examples-web-smoke-prebuild.sh';
        $this->assertFileExists($prebuild);
        $this->assertTrue(is_executable($prebuild));
        $body = (string) file_get_contents($prebuild);
        $this->assertStringContainsString('001-SimpleWeb', $body);
        $this->assertStringContainsString('phpc build --project', $body);
    }

    public function testCiResourceLimitsSourcesDefaults(): void
    {
        $limits = dirname(__DIR__, 2).'/script/ci-resource-limits.sh';
        $body = (string) file_get_contents($limits);
        $this->assertStringContainsString('ci-defaults.env', $body);
        $this->assertStringContainsString('PHP_COMPILER_CI_RAM_GB', $body);
    }

    public function testCiDefaultsEnvDefinesRepositoryDefaults(): void
    {
        $defaults = dirname(__DIR__, 2).'/script/ci-defaults.env';
        $this->assertFileExists($defaults);
        $body = (string) file_get_contents($defaults);
        $this->assertStringContainsString('PHP_COMPILER_CI_RAM_GB="${PHP_COMPILER_CI_RAM_GB:-8}"', $body);
        $this->assertStringContainsString('PHP_COMPILER_MEMORY_LIMIT="${PHP_COMPILER_MEMORY_LIMIT:-1536M}"', $body);
        $this->assertStringContainsString('PHP_COMPILER_DOCKER_MEM="${PHP_COMPILER_DOCKER_MEM:-10g}"', $body);
    }

    public function testDockerCiLocalUsesMemoryCappedRun(): void
    {
        $local = dirname(__DIR__, 2).'/script/docker-ci-local.sh';
        $body = (string) file_get_contents($local);
        $this->assertStringContainsString('ci-docker-run.sh', $body);
        $this->assertStringContainsString('ci_docker_run', $body);
        $this->assertStringContainsString('ci-defaults.env', $body);
    }

    public function testCiFastPreparesRuntimeLimits(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('ci_prepare_test_runtime', $body);
    }

    public function testAotLinkGroupTaggedOnAotTest(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/aot/AotTest.php');
        $this->assertStringContainsString('@group aot-link', $source);
    }

    public function testMiniWebAppGatesScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/miniwebapp-gates.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE', $body);
        $this->assertStringContainsString('issues/461', $body);
    }
}
