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
        $this->assertStringContainsString('ci_run_aot_link_phpunit', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_aot_execute', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_serve_aot', $body);
        $this->assertStringContainsString('ci_prepare_test_runtime', $body);
        $this->assertStringContainsString('ci_run_examples_web_smoke_aot', $body);
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('--group aot-link', $common);
        $this->assertStringContainsString('ci_run_phpunit', $common);
        $this->assertStringContainsString('ci_export_llvm_env', $common);
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
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE:-1', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_web_smoke', $body);

        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-web-smoke.sh');
        $this->assertStringContainsString('--miniwebapp-only', $smoke);
    }

    public function testCiFastDoesNotRunMiniWebAppWebSmokeGate(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringNotContainsString('ci_run_miniwebapp_web_smoke', $body);
        $this->assertStringNotContainsString('examples-web-smoke.sh', $body);
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

    public function testCiDefaultsEnvDefinesMiniWebAppWebSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE="${MINIWEBAPP_WEB_SMOKE_GATE:-1}"', $defaults);
    }

    public function testCiDefaultsEnvDefinesMiniWebAppAotGates(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('MINIWEBAPP_AOT_LINK_GATE="${MINIWEBAPP_AOT_LINK_GATE:-1}"', $defaults);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE="${MINIWEBAPP_AOT_EXECUTE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_AOT_GATE="${MINIWEBAPP_SERVE_AOT_GATE:-1}"', $defaults);
    }

    public function testExamplesCompileTestHonorsMiniWebAppAotLinkGate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/unit/ExamplesCompileTest.php');
        $this->assertStringContainsString('MINIWEBAPP_AOT_LINK_GATE', $source);
        $this->assertStringContainsString('miniWebAppAotLinkGateEnabled', $source);
        $this->assertStringContainsString('test003MiniWebAppBuildLinks', $source);
        $this->assertStringContainsString('test003MiniWebAppHomeRouteAotExecutes', $source);
        $this->assertStringContainsString('miniWebAppAotExecuteGateEnabled', $source);
        $this->assertStringContainsString('@group miniwebapp-aot-execute', $source);
    }

    public function testCiLocalExcludesMiniWebAppAotExecuteUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_aot_link_phpunit', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_aot_execute', $body);
        $this->assertStringContainsString('ci_run_miniwebapp_serve_aot', $body);
        $this->assertStringContainsString('--exclude-group miniwebapp-aot-execute', $body);
        $this->assertStringContainsString('--exclude-group miniwebapp-aot-serve', $body);
        $this->assertStringContainsString('--group miniwebapp-aot-execute', $body);
        $this->assertStringContainsString('--group miniwebapp-aot-serve', $body);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE:-1', $body);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_AOT_GATE:-1', $body);
    }

    public function testExamplesWebSmokePrebuildScriptExists(): void
    {
        $prebuild = dirname(__DIR__, 2).'/script/examples-web-smoke-prebuild.sh';
        $this->assertFileExists($prebuild);
        $this->assertTrue(is_executable($prebuild));
        $body = (string) file_get_contents($prebuild);
        $this->assertStringContainsString('001-SimpleWeb', $body);
        $this->assertStringContainsString('003-MiniWebApp', $body);
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

    public function testDeploySmokeScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/deploy-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('phpc deploy', $body);
        $this->assertStringContainsString('PHPC_DEPLOY_ROOT', $body);
        $this->assertStringContainsString('002-StaticWeb', $body);

        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('deploy-smoke:', $makefile);
        $this->assertStringContainsString('deploy-smoke.sh', $makefile);
    }

    public function testCiLocalHonorsExamplesAotSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_examples_aot_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE', $common);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('examples-aot-smoke.sh', $common);
    }

    public function testCiLocalHonorsDeploySmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_deploy_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE', $common);
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('deploy-smoke.sh', $common);
        $this->assertStringContainsString('--example 001', $common);
        $this->assertStringContainsString('--example 002', $common);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_EXECUTE', $common);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE', $common);
        $this->assertStringContainsString('--example 003', $common);
    }

    public function testCiDefaultsEnvDefinesDeploySmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE="${DEPLOY_SMOKE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_EXECUTE="${DEPLOY_SMOKE_003_EXECUTE:-0}"', $defaults);
    }

    public function testCiFastDoesNotRunDeploySmokeGate(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringNotContainsString('ci_run_deploy_smoke', $body);
        $this->assertStringNotContainsString('deploy-smoke.sh', $body);
    }

    public function testCiDefaultsEnvDefinesExamplesAotSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE="${EXAMPLES_AOT_SMOKE_GATE:-1}"', $defaults);
    }

    public function testCiLocalHonorsBootstrapSelfhostProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_selfhost_probe', $local);
        $this->assertStringContainsString('BOOTSTRAP_SELFHOST_PROBE_GATE:-1', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_SELFHOST_PROBE_GATE', $common);
        $this->assertStringContainsString('bootstrap-selfhost-compile-probe.sh', $common);
        $this->assertStringContainsString('BOOTSTRAP_SELFHOST_PROBE_UPDATE', $common);
    }

    public function testCiLocalHonorsBootstrapLoopProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_loop_probe', $local);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE:-0', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $common);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $common);
        $this->assertStringContainsString('--dry-run', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LOOP_PROBE_GATE="${BOOTSTRAP_LOOP_PROBE_GATE:-0}"',
            $defaults
        );
    }

    public function testCiLocalHonorsBootstrapWaveCheckGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_wave_check', $local);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK:-1', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK', $common);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK:-1', $common);
        $this->assertStringContainsString('bootstrap-wave-check.sh', $common);
        $this->assertStringContainsString('--fail-fast', $common);

        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP', $fast);
        $this->assertStringContainsString('ci_run_bootstrap_wave_check', $fast);
    }

    public function testCiDefaultsEnvDefinesBootstrapSelfhostProbeUpdateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringNotContainsString('BOOTSTRAP_SELFHOST_PROBE_GATE', $defaults);
        $this->assertStringContainsString(
            'BOOTSTRAP_SELFHOST_PROBE_UPDATE="${BOOTSTRAP_SELFHOST_PROBE_UPDATE:-0}"',
            $defaults
        );
    }

    public function testCiFastDoesNotRunBootstrapSelfhostProbeGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP', $fast);
        $this->assertStringContainsString('ci_run_bootstrap_selfhost_probe', $fast);
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP:-0', $fast);
    }

    public function testCiFastDoesNotRunExamplesAotSmokeGate(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringNotContainsString('ci_run_examples_aot_smoke', $body);
        $this->assertStringNotContainsString('examples-aot-smoke.sh', $body);
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

    public function testCiDockerRunPassesJitPreflightGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('JIT_PREFLIGHT_GATE', $body);
    }

    public function testCiFastPreparesRuntimeLimits(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('ci_prepare_test_runtime', $body);
    }

    public function testCiFastSupportsOptionalBootstrapTail(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP:-0', $fast);
        $this->assertStringContainsString('ci_run_bootstrap_aot_lint', $fast);
        $this->assertStringContainsString('--group aot-lint', $fast);
        $this->assertStringContainsString('bootstrap tail skipped (LLVM 9 not available)', $fast);

        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('test-fast-bootstrap:', $makefile);
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP=1', $makefile);
    }

    public function testLocalCiMatrixDocumentsBootstrapWaveCheckDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK', $doc);
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP', $doc);
        $this->assertStringContainsString('test-fast-bootstrap', $doc);
    }

    public function testBootstrapSelfhostDocMarksProbeAndWaveCheckGreen(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('Self-host probe in full CI', $doc);
        $this->assertStringContainsString('Wave gate in full CI', $doc);
        $this->assertStringContainsString('default-on when LLVM 9 present', $doc);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK=0', $doc);
    }

    public function testCiFastSupportsOptionalJitPreflightGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_jit_preflight_gate', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('JIT_PREFLIGHT_GATE', $common);
        $this->assertStringContainsString('check-jit-compliance-ran.php --preflight', $common);

        $probe = (string) file_get_contents(dirname(__DIR__, 2).'/script/check-jit-compliance-ran.php');
        $this->assertStringContainsString('--preflight', $probe);

        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('test-fast-jit-preflight:', $makefile);
        $this->assertStringContainsString('JIT_PREFLIGHT_GATE=1', $makefile);
    }

    public function testLocalCiMatrixDocumentsJitPreflightGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('JIT_PREFLIGHT_GATE', $doc);
        $this->assertStringContainsString('test-fast-jit-preflight', $doc);
        $this->assertStringContainsString('--preflight', $doc);
    }

    public function testLocalCiMatrixDocumentsMiniWebAppGates(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('## MiniWebApp gates', $doc);
        $this->assertStringContainsString('miniwebapp-gates.sh', $doc);
        $this->assertStringContainsString('phpc doctor --gates', $doc);
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_AOT_LINK_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE', $doc);
        $this->assertStringContainsString('ci_run_miniwebapp_aot_execute', $doc);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE', $doc);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_ONLY', $doc);
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE=1', $doc);
        $this->assertStringContainsString('MINIWEBAPP_AOT_LINK_GATE=0', $doc);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE="${MINIWEBAPP_AOT_EXECUTE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('MINIWEBAPP_AOT_LINK_GATE="${MINIWEBAPP_AOT_LINK_GATE:-1}"', $defaults);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_GATE="${EXAMPLES_AOT_SMOKE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE="${DEPLOY_SMOKE_GATE:-1}"', $defaults);
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

    public function testMiniWebAppAotBisectScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/miniwebapp-aot-bisect.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('miniwebapp-aot-bisect', $body);
        $this->assertStringContainsString('issues/764', $body);
        $this->assertStringContainsString('issues/879', $body);
        $this->assertStringContainsString('isset_object_property_array', $body);
        $this->assertStringContainsString('nested_include_two_tier', $body);
        $this->assertStringContainsString('--from', $body);
        $this->assertStringContainsString('--list', $body);
    }

    public function testCiLocalDocumentsMiniWebAppBisectGroup(): void
    {
        $ciLocal = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('miniwebapp-bisect', $ciLocal);
        $bisectTest = (string) file_get_contents(dirname(__DIR__).'/aot/MiniWebAppBisectAotTest.php');
        $this->assertStringContainsString('@group miniwebapp-bisect', $bisectTest);
    }

    public function testMiniWebAppGatesScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/miniwebapp-gates.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('MINIWEBAPP_AOT_BISECT_GATE', $body);
        $this->assertStringContainsString('miniwebapp-aot-bisect.sh', $body);
        $this->assertStringContainsString('issues/879', $body);
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
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE:-1', $body);
        $this->assertStringContainsString('issues/633', $body);
        $this->assertStringContainsString('issues/664', $body);
        $this->assertStringContainsString('Stage 4a AOT dry-run', $body);
        $this->assertStringContainsString('issues/624', $body);
        $this->assertStringContainsString('Stage 4c AOT smoke', $body);
        $this->assertStringContainsString('EXAMPLES_AOT_SMOKE_ONLY=003', $body);
        $this->assertStringContainsString('issues/683', $body);
        $this->assertStringContainsString('Stage 4d deploy-smoke', $body);
        $this->assertStringContainsString('issues/718', $body);
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
        $this->assertStringContainsString('docker-build-22', $makefile);
        $this->assertStringContainsString('docker-publish-dev', $makefile);
    }

    public function testDockerPublishDevScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/docker-publish-dev.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('ghcr.io/PurHur/php-compiler:dev', $body);
        $this->assertStringContainsString('php-compiler:22.04-dev', $body);
        $this->assertStringContainsString('Docker/dev/ubuntu-22.04/Dockerfile', $body);
    }

    public function testLocalCiMatrixDocumentsDockerDevImage(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('make docker-build-22', $doc);
        $this->assertStringContainsString('docker-publish-dev.sh', $doc);
        $this->assertStringContainsString('ghcr.io/PurHur/php-compiler:dev', $doc);
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

    public function testCheckInitMiniWebAppParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-miniwebapp-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/003-MiniWebApp', $body);
        $this->assertStringContainsString('templates/init-miniwebapp', $body);
    }

    public function testCheckInitMiniWebAppParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-miniwebapp-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitMiniWebAppParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('check-init-miniwebapp-parity.sh', $common);
    }
}
