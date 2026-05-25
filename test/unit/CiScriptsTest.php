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

    public function testCiLocalHonorsMiniWebAppWebSmokeAotGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $local);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1', $local);
        $this->assertStringContainsString('ci_run_miniwebapp_web_smoke_aot', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $common);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1', $common);
        $this->assertStringContainsString('--miniwebapp-only --aot', $common);
    }

    public function testCiDefaultsEnvDefinesMiniWebAppWebSmokeAotGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'MINIWEBAPP_WEB_SMOKE_AOT_GATE="${MINIWEBAPP_WEB_SMOKE_AOT_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1523', $defaults);
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
        $this->assertStringNotContainsString('examples-web-smoke.sh --miniwebapp-only', $body);
    }

    public function testCiFastRunsSessionsWebSmokeGate(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('ci_run_sessions_web_smoke', $body);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('SESSIONS_WEB_SMOKE_GATE', $common);
        $this->assertStringContainsString('--sessions-only', $common);
    }

    public function testCiDefaultsEnvDefinesSessionsWebAotSmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE="${SESSIONS_WEB_AOT_SMOKE_GATE:-0}"', $defaults);
    }

    public function testCiLocalExcludesSessionsWebAotExecuteUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_sessions_web_aot_execute', $body);
        $this->assertStringContainsString('--exclude-group sessionsweb-aot-execute', $body);
        $this->assertStringContainsString('--group sessionsweb-aot-execute', $body);
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE:-0', $body);
    }

    public function testCiDefaultsEnvDefinesSessionsWebSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SESSIONS_WEB_SMOKE_GATE="${SESSIONS_WEB_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiLocalRunsSessionsWebSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_sessions_web_smoke', $local);
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
        $this->assertStringContainsString('DEPLOY_SMOKE_003_EXECUTE:-1', $common);
        $this->assertStringContainsString('MINIWEBAPP_AOT_EXECUTE_GATE', $common);
        $this->assertStringContainsString('--example 003', $common);
    }

    public function testCiDefaultsEnvDefinesDeploySmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('DEPLOY_SMOKE_GATE="${DEPLOY_SMOKE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('DEPLOY_SMOKE_003_EXECUTE="${DEPLOY_SMOKE_003_EXECUTE:-1}"', $defaults);
    }

    public function testCiFastDoesNotRunDeploySmokeGate(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringNotContainsString('ci_run_deploy_smoke', $body);
        $this->assertStringNotContainsString('deploy-smoke.sh', $body);
    }

    public function testCiDefaultsEnvDefinesWave3RoadmapSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('WAVE3_ROADMAP_SYNC_GATE="${WAVE3_ROADMAP_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsWave3RoadmapSyncViaInventoryChecks(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_inventory_checks', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_wave3_roadmap_sync_check', $common);
        $this->assertStringContainsString('check-wave3-roadmap-sync.php', $common);
        $this->assertStringContainsString('WAVE3_ROADMAP_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesWave3RoadmapSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('WAVE3_ROADMAP_SYNC_GATE=${WAVE3_ROADMAP_SYNC_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesExamplesReadmeSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('EXAMPLES_README_SYNC_GATE="${EXAMPLES_README_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsExamplesReadmeSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_examples_readme_sync_check', $common);
        $this->assertStringContainsString('check-examples-readme-sync.php', $common);
        $this->assertStringContainsString('EXAMPLES_README_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesExamplesReadmeSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('EXAMPLES_README_SYNC_GATE=${EXAMPLES_README_SYNC_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesExamplesLadderDiscoveryGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('EXAMPLES_LADDER_DISCOVERY_GATE="${EXAMPLES_LADDER_DISCOVERY_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsExamplesLadderDiscoveryViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_examples_ladder_discovery_check', $common);
        $this->assertStringContainsString('check-examples-ladder-discovery.php', $common);
        $this->assertStringContainsString('EXAMPLES_LADDER_DISCOVERY_GATE:-1', $common);
    }

    public function testCiDockerRunPassesExamplesLadderDiscoveryGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('EXAMPLES_LADDER_DISCOVERY_GATE=${EXAMPLES_LADDER_DISCOVERY_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesRootReadmeSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ROOT_README_SYNC_GATE="${ROOT_README_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsRootReadmeSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_root_readme_sync_check', $common);
        $this->assertStringContainsString('check-root-readme-sync.php', $common);
        $this->assertStringContainsString('ROOT_README_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesRootReadmeSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ROOT_README_SYNC_GATE=${ROOT_README_SYNC_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesSelfhostSpineCountSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_SPINE_COUNT_SYNC_GATE="${SELFHOST_SPINE_COUNT_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsSelfhostSpineCountSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_spine_count_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-spine-count-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_SPINE_COUNT_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesSelfhostSpineCountSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_SPINE_COUNT_SYNC_GATE=${SELFHOST_SPINE_COUNT_SYNC_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesM3AllowlistSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('M3_ALLOWLIST_SYNC_GATE="${M3_ALLOWLIST_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsM3AllowlistSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_m3_allowlist_sync_check', $common);
        $this->assertStringContainsString('check-m3-allowlist-snapshot.php', $common);
        $this->assertStringContainsString('M3_ALLOWLIST_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesM3AllowlistSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('M3_ALLOWLIST_SYNC_GATE=${M3_ALLOWLIST_SYNC_GATE:-1}', $body);
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

    public function testCiFastHonorsBootstrapLoopProbeGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_bootstrap_loop_probe', $fast);
    }

    public function testCiLocalHonorsBootstrapLoopProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_loop_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $common);
        $this->assertStringContainsString('--dry-run', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LOOP_PROBE_GATE="${BOOTSTRAP_LOOP_PROBE_GATE:-0}"',
            $defaults
        );
    }

    public function testLocalCiMatrixDocumentsBootstrapLoopProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-loop-probe.sh --dry-run', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE=1', $docSelfhost);
        $this->assertStringContainsString('ci-fast.sh', $doc);
        $this->assertStringContainsString('#1929', $doc);
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

    public function testCiLocalHonorsBootstrapM3StrictGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1', $common);
        $probe = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('block_reason=', $probe);
        $this->assertStringContainsString('NEXT_LOWER', $probe);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE="${BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#1526', $defaults);
    }

    public function testLocalCiMatrixDocumentsBootstrapM3StrictGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=1', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesBootstrapLibSpineVmSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE="${BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1867', $defaults);
    }

    public function testCiLocalHonorsBootstrapLibSpineVmSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_lib_spine_vm_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke.sh', $common);
    }

    public function testLocalCiMatrixDocumentsBootstrapLibSpineVmSmokeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-vm-smoke.sh', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_LIB_SPINE_VM_SMOKE_GATE=1', $docSelfhost);
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

    public function testLocalCiMatrixDocumentsWave3RoadmapSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('WAVE3_ROADMAP_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-wave3-roadmap-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `WAVE3_ROADMAP_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsExamplesReadmeSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('EXAMPLES_README_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-examples-readme-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `EXAMPLES_README_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsExamplesLadderDiscoveryGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('EXAMPLES_LADDER_DISCOVERY_GATE', $doc);
        $this->assertStringContainsString('check-examples-ladder-discovery.php', $doc);
        $this->assertMatchesRegularExpression('/\| `EXAMPLES_LADDER_DISCOVERY_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsRootReadmeSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-root-readme-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsSelfhostSpineCountSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_SPINE_COUNT_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-spine-count-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_SPINE_COUNT_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsMiniWebAppGates(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('## MiniWebApp gates', $doc);
        $this->assertStringContainsString('miniwebapp-gates.sh', $doc);
        $this->assertStringContainsString('phpc doctor --gates', $doc);
        $this->assertStringContainsString('north-star1-verify', $doc);
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_SERVE_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_GATE', $doc);
        $this->assertStringContainsString('MINIWEBAPP_WEB_SMOKE_AOT_GATE', $doc);
        $this->assertStringContainsString('ci_run_miniwebapp_web_smoke_aot', $doc);
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

    public function testCiDefaultsEnvDefinesNestedReturnComplianceGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'NESTED_RETURN_COMPLIANCE_GATE="${NESTED_RETURN_COMPLIANCE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1888', $defaults);
    }

    public function testCiFastRunsNestedReturnComplianceGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('NESTED_RETURN_COMPLIANCE_GATE', $fast);
        $this->assertStringContainsString('NESTED_RETURN_COMPLIANCE_GATE:-1', $fast);
        $this->assertStringContainsString('--filter NestedReturn', $fast);
    }

    public function testLocalCiMatrixDocumentsNestedReturnComplianceGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NESTED_RETURN_COMPLIANCE_GATE', $doc);
        $this->assertStringContainsString('NestedReturn*', $doc);
        $this->assertMatchesRegularExpression('/\| `NESTED_RETURN_COMPLIANCE_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesNestedReturnComplianceGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('NESTED_RETURN_COMPLIANCE_GATE', $body);
    }

    public function testCiDefaultsEnvDefinesAttributesComplianceGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'ATTRIBUTES_COMPLIANCE_GATE="${ATTRIBUTES_COMPLIANCE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1904', $defaults);
    }

    public function testCiFastRunsAttributesComplianceGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ATTRIBUTES_COMPLIANCE_GATE', $fast);
        $this->assertStringContainsString('ATTRIBUTES_COMPLIANCE_GATE:-1', $fast);
        $this->assertStringContainsString('--filter Attribute', $fast);
    }

    public function testLocalCiMatrixDocumentsAttributesComplianceGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ATTRIBUTES_COMPLIANCE_GATE', $doc);
        $this->assertStringContainsString('Attribute*', $doc);
        $this->assertMatchesRegularExpression('/\| `ATTRIBUTES_COMPLIANCE_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesAttributesComplianceGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ATTRIBUTES_COMPLIANCE_GATE', $body);
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
        $this->assertStringContainsString('class_method_json_api', $body);
        $this->assertStringContainsString('render_hello_request_assign', $body);
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

    public function testClassMethodJsonApiAotFixtureRegistered(): void
    {
        $fixture = dirname(__DIR__).'/fixtures/aot/cases/class_method_json_api.phpt';
        $this->assertFileExists($fixture);
        $body = (string) file_get_contents($fixture);
        $this->assertStringContainsString('renderApiStatus', $body);
        $this->assertStringContainsString('"ok":true', $body);

        $executeTest = (string) file_get_contents(dirname(__DIR__).'/aot/ClassMethodJsonApiAotTest.php');
        $this->assertStringContainsString('@group miniwebapp-aot-execute', $executeTest);
        $this->assertStringContainsString('class_method_json_api', $executeTest);
    }

    public function testMiniWebAppBisectLadderMatchesScript(): void
    {
        $phpTest = (string) file_get_contents(dirname(__DIR__).'/aot/MiniWebAppBisectAotTest.php');
        preg_match('/private const BISECT_LADDER = \[(.*?)\];/s', $phpTest, $phpMatch);
        $this->assertNotEmpty($phpMatch[1] ?? null);
        preg_match_all("/'([^']+)'/", $phpMatch[1], $phpSteps);
        $phpLadder = $phpSteps[1];

        $script = (string) file_get_contents(dirname(__DIR__, 2).'/script/miniwebapp-aot-bisect.sh');
        preg_match('/readonly -a BISECT_STEPS=\((.*?)\)/s', $script, $scriptMatch);
        $this->assertNotEmpty($scriptMatch[1] ?? null);
        preg_match_all("/'([^|']+)\|/", $scriptMatch[1], $scriptSteps);
        $scriptLadder = $scriptSteps[1];

        $this->assertSame($phpLadder, $scriptLadder, 'MiniWebAppBisectAotTest BISECT_LADDER must match miniwebapp-aot-bisect.sh BISECT_STEPS');
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

    public function testCheckInitSessionswebParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-sessionsweb-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/005-SessionsWeb', $body);
        $this->assertStringContainsString('templates/init-sessionsweb', $body);
    }

    public function testCheckInitSessionswebParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-sessionsweb-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitSessionswebParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_sessionsweb_parity_check', $common);
        $this->assertStringContainsString('check-init-sessionsweb-parity.sh', $common);
    }
}
