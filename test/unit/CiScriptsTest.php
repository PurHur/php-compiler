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

    public function testCiLocalHonorsMiniWebAppServeGate(): void
    {
        $local = dirname(__DIR__, 2).'/script/ci-local.sh';
        $body = (string) file_get_contents($local);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $body);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE:-1', $body);
        $this->assertStringContainsString('--group miniwebapp', $body);
        $this->assertStringContainsString('--fail-on-skipped', $body);
    }

    public function testCiLocalHonorsMiniWebAppWebSmokeGate(): void
    {
        $local = dirname(__DIR__, 2).'/script/ci-local.sh';
        $body = (string) file_get_contents($local);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_web_smoke', $body);

        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-web-smoke.sh');
        $this->assertStringContainsString('--miniwebapp-only', $smoke);
    }

    public function testCiFastHonorsMiniWebAppServeGate(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $body);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE:-1', $body);
        $this->assertStringContainsString('--group miniwebapp', $body);
        $this->assertStringContainsString('--fail-on-skipped', $body);
    }

    public function testCiDefaultsEnvDefinesMiniWebAppServeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE="${MINIWEBAPP_SERVE_GATE:-1}"', $defaults);
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

    public function testExamplesAotSmokeScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/examples-aot-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('000-HelloWorld', $body);
        $this->assertStringContainsString('QUERY_STRING=name=Smoke', $body);
        $this->assertStringContainsString('.phpc/smoke', $body);
    }

    public function testCiLocalHonorsExamplesAotSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_examples_aot_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE', $common);
        $this->assertStringContainsString('examples-aot-smoke.sh', $common);
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

    public function testCiFastRunsMiniWebAppVmCliGateByDefault(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $body);
        $this->assertStringContainsString("MiniWebApp.*VmCli", $body);
        $this->assertStringContainsString('PhpcLintProjectTest', $body);
    }

    public function testCiFastRunsCgiDriverTest(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('CgiDriverTest', $body);
        $this->assertStringContainsString('exclude-group llvm,serve,cgi', $body);
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
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $body);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $body);
        $this->assertStringContainsString('issues/461', $body);
        $this->assertStringContainsString('issues/597', $body);
        $this->assertStringContainsString('issues/621', $body);
        $this->assertStringContainsString('issues/622', $body);
        $this->assertStringContainsString('issues/641', $body);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE:-1', $body);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE', $body);
        $this->assertStringContainsString('issues/633', $body);
    }

    public function testWebSmokeDefaultsMiniWebAppLintGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/web-smoke.sh');
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE:-1}', $body);
        $this->assertStringContainsString('MINIWEBAPP_LINT_GATE=0', $body);
        $this->assertStringContainsString('#621', $body);
    }

    public function testRunVmGuardedScriptExists(): void
    {
        $guard = dirname(__DIR__, 2).'/script/run-vm-guarded.sh';
        $this->assertFileExists($guard);
        $this->assertTrue(is_executable($guard));
        $body = (string) file_get_contents($guard);
        $this->assertStringContainsString('PHP_COMPILER_VM_PEAK_RSS_MB', $body);
    }

    public function testMakefileHasTestDockerSafeAlias(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('test-docker-safe:', $makefile);
        $this->assertStringContainsString('ci-docker-safe.sh', $makefile);
    }

    public function testLocalCiMatrixDocExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
    }

    public function testCiDefaultsVmRssGuard(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('PHP_COMPILER_VM_PEAK_RSS_MB', $body);
        $this->assertStringContainsString('PHP_COMPILER_VM_RSS_GUARD', $body);
    }

    public function testCheckNoUnlimitedMemoryScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-no-unlimited-memory.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
    }

    public function testCheckNoUnlimitedMemoryPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-no-unlimited-memory.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsUnlimitedMemoryCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('check-no-unlimited-memory.sh', $common);
    }
}
