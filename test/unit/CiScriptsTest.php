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

    public function testCiDefaultsEnvDefinesSessionsWebAotLinkGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SESSIONS_WEB_AOT_LINK_GATE="${SESSIONS_WEB_AOT_LINK_GATE:-1}"', $defaults);
    }

    public function testExamplesCompileTestHonorsSessionsWebAotLinkGate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/unit/ExamplesCompileTest.php');
        $this->assertStringContainsString('SESSIONS_WEB_AOT_LINK_GATE', $source);
        $this->assertStringContainsString('sessionsWebAotLinkGateEnabled', $source);
        $this->assertStringContainsString('test005SessionsWebAotLink', $source);
        $this->assertStringContainsString('@group aot-link', $source);
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

    public function testCiDefaultsEnvDefinesFileUploadWebSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'FILE_UPLOAD_WEB_SMOKE_GATE="${FILE_UPLOAD_WEB_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiDefaultsEnvDefinesFileUploadWebAotLinkGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_LINK_GATE="${FILE_UPLOAD_WEB_AOT_LINK_GATE:-1}"', $defaults);
    }

    public function testCiDefaultsEnvDefinesFileUploadWebAotSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_SMOKE_GATE="${FILE_UPLOAD_WEB_AOT_SMOKE_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsFileUploadWebSmokeGate(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('ci_run_file_upload_web_smoke', $body);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SMOKE_GATE', $common);
        $this->assertStringContainsString('--fileupload-only', $common);
    }

    public function testCiLocalRunsFileUploadWebSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_file_upload_web_smoke', $local);
    }

    public function testExamplesWebSmokeDefinesFileUploadOnlyFlag(): void
    {
        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-web-smoke.sh');
        $this->assertStringContainsString('--fileupload-only', $smoke);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SMOKE_GATE', $smoke);
        $this->assertStringContainsString('006-FileUploadWeb', $smoke);
    }

    public function testCiDefaultsEnvDefinesThrowsWebSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'THROWS_WEB_SMOKE_GATE="${THROWS_WEB_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiFastRunsThrowsWebSmokeGate(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('ci_run_throws_web_smoke', $body);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $common);
        $this->assertStringContainsString('--throws-only', $common);
    }

    public function testCiLocalRunsThrowsWebSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_throws_web_smoke', $local);
    }

    public function testExamplesWebSmokeDefinesThrowsOnlyFlag(): void
    {
        $smoke = (string) file_get_contents(dirname(__DIR__, 2).'/script/examples-web-smoke.sh');
        $this->assertStringContainsString('--throws-only', $smoke);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $smoke);
        $this->assertStringContainsString('007-ThrowsWeb', $smoke);
    }

    public function testExamplesCompileTestHonorsFileUploadWebAotLinkGate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/unit/ExamplesCompileTest.php');
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_LINK_GATE', $source);
        $this->assertStringContainsString('fileUploadWebAotLinkGateEnabled', $source);
        $this->assertStringContainsString('test006FileUploadWebAotLink', $source);
    }

    public function testExamplesCompileTestHonorsThrowsWebAotLinkGate(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/unit/ExamplesCompileTest.php');
        $this->assertStringContainsString('THROWSWEB_AOT_LINK_GATE', $source);
        $this->assertStringContainsString('throwsWebAotLinkGateEnabled', $source);
        $this->assertStringContainsString('test007ThrowsWebAotLink', $source);
        $this->assertStringContainsString('@group aot-link', $source);
    }

    public function testCiLocalExcludesFileUploadWebAotExecuteUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_file_upload_web_aot_execute', $body);
        $this->assertStringContainsString('--exclude-group fileuploadweb-aot-execute', $body);
        $this->assertStringContainsString('--group fileuploadweb-aot-execute', $body);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_AOT_SMOKE_GATE:-1', $body);
    }

    public function testCiLocalExcludesThrowsWebAotExecuteUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_throws_web_aot_execute', $body);
        $this->assertStringContainsString('--exclude-group throwsweb-aot-execute', $body);
        $this->assertStringContainsString('--group throwsweb-aot-execute', $body);
        $this->assertStringContainsString('THROWSWEB_AOT_SMOKE_GATE:-1}', $body);
    }

    public function testCiLocalRunsThrowsWebAotLinkBeforeExecute(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $linkPos = strpos($local, 'ci_run_aot_link_phpunit');
        $executePos = strpos($local, 'ci_run_throws_web_aot_execute');
        $this->assertNotFalse($linkPos, 'ci-local.sh must call ci_run_aot_link_phpunit');
        $this->assertNotFalse($executePos, 'ci-local.sh must call ci_run_throws_web_aot_execute');
        $this->assertLessThan($executePos, $linkPos, 'AOT link must run before ThrowsWeb AOT execute (#2178)');
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

    public function testMakefileHasExamplesThrowsSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-throws-smoke:', $makefile);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE=1', $makefile);
        $this->assertStringContainsString('examples-web-smoke.sh --throws-only', $makefile);
        $this->assertStringContainsString('examples-throws-smoke', $makefile);
    }

    public function testMakefileHasExamplesFileuploadDeploySmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-fileupload-deploy-smoke:', $makefile);
        $this->assertStringContainsString('examples-fileupload-deploy-smoke.sh', $makefile);

        $script = dirname(__DIR__, 2).'/script/examples-fileupload-deploy-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=1', $body);
        $this->assertStringContainsString('deploy-smoke.sh --example 006', $body);
    }

    public function testMakefileHasDeploySmokeAllTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('deploy-smoke-all:', $makefile);
        $this->assertStringContainsString('deploy-smoke-all.sh', $makefile);
        $this->assertStringContainsString('DEPLOY_SMOKE_ALL', $makefile);

        $script = dirname(__DIR__, 2).'/script/deploy-smoke-all.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('deploy-smoke.sh', $body);
        $this->assertStringContainsString('run_example 001', $body);
        $this->assertStringContainsString('run_example 005', $body);
        $this->assertStringContainsString('run_example 006', $body);
        $this->assertStringContainsString('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('skip (SESSIONS_WEB_DEPLOY_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('skip (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('deploy-smoke-all: ok', $body);
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
        $this->assertStringContainsString('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $common);
        $this->assertStringContainsString('--example 005', $common);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE', $common);
        $this->assertStringContainsString('--example 006', $common);
    }

    public function testCiDefaultsEnvDefinesSessionsWebDeploySmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SESSIONS_WEB_DEPLOY_SMOKE_GATE="${SESSIONS_WEB_DEPLOY_SMOKE_GATE:-0}"',
            $defaults
        );
    }

    public function testCiDefaultsEnvDefinesFileUploadWebDeploySmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE="${FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE:-0}"',
            $defaults
        );
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

    public function testCiDefaultsEnvDefinesRebuildExamples005SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE="${REBUILD_EXAMPLES_005_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiDefaultsEnvDefinesRebuildExamples006SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('REBUILD_EXAMPLES_006_SYNC_GATE="${REBUILD_EXAMPLES_006_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsRebuildExamples005SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_rebuild_examples_005_sync_check', $common);
        $this->assertStringContainsString('check-rebuild-examples-005-row.php', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE:-1', $common);
    }

    public function testCiFastRunsRebuildExamples006SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_rebuild_examples_006_sync_check', $common);
        $this->assertStringContainsString('check-rebuild-examples-006-row.php', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_006_SYNC_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesCapabilities006SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('CAPABILITIES_006_SYNC_GATE="${CAPABILITIES_006_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsCapabilities006SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_capabilities_fileuploadweb_sync_check', $common);
        $this->assertStringContainsString('check-capabilities-fileuploadweb-sync.php', $common);
        $this->assertStringContainsString('CAPABILITIES_006_SYNC_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesCapabilitiesThrowsSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('CAPABILITIES_THROWS_SYNC_GATE="${CAPABILITIES_THROWS_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsCapabilitiesThrowsSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_capabilities_throws_sync_check', $common);
        $this->assertStringContainsString('check-capabilities-throws-sync.php', $common);
        $this->assertStringContainsString('CAPABILITIES_THROWS_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesRebuildExamples005SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE=${REBUILD_EXAMPLES_005_SYNC_GATE:-1}', $body);
    }

    public function testCiDockerRunPassesRebuildExamples006SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('REBUILD_EXAMPLES_006_SYNC_GATE=${REBUILD_EXAMPLES_006_SYNC_GATE:-1}', $body);
    }

    public function testCiDockerRunPassesCapabilities006SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('CAPABILITIES_006_SYNC_GATE=${CAPABILITIES_006_SYNC_GATE:-1}', $body);
        $this->assertStringContainsString('CAPABILITIES_THROWS_SYNC_GATE=${CAPABILITIES_THROWS_SYNC_GATE:-1}', $body);
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

    public function testCiDefaultsEnvDefinesDevelopmentStatusSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_SYNC_GATE="${DEVELOPMENT_STATUS_SYNC_GATE:-1}"',
            $defaults
        );
    }

    public function testCiFastRunsDevelopmentStatusSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_development_status_sync_check', $common);
        $this->assertStringContainsString('check-development-status-sync.php', $common);
        $this->assertStringContainsString('DEVELOPMENT_STATUS_SYNC_GATE:-1', $common);
        $this->assertStringContainsString('DEVELOPMENT_STATUS_SYNC_GATE=0 opt-out', $common);
    }

    public function testCiDockerRunPassesDevelopmentStatusSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_SYNC_GATE=${DEVELOPMENT_STATUS_SYNC_GATE:-1}',
            $body
        );
    }

    public function testLocalCiMatrixDocumentsDevelopmentStatusSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DEVELOPMENT_STATUS_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-development-status-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `DEVELOPMENT_STATUS_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2083', $doc);
    }

    public function testCiDefaultsEnvDefinesDevelopmentStatus007SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_007_SYNC_GATE="${DEVELOPMENT_STATUS_007_SYNC_GATE:-1}"',
            $defaults
        );
    }

    public function testCiFastRunsDevelopmentStatus007SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_development_status_007_sync_check', $common);
        $this->assertStringContainsString('DEVELOPMENT_STATUS_007_SYNC_GATE=1', $common);
    }

    public function testCiDockerRunPassesDevelopmentStatus007SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_007_SYNC_GATE=${DEVELOPMENT_STATUS_007_SYNC_GATE:-1}',
            $body
        );
    }

    public function testLocalCiMatrixDocumentsDevelopmentStatus007SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DEVELOPMENT_STATUS_007_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `DEVELOPMENT_STATUS_007_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2145', $doc);
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

    public function testCiDefaultsEnvDefinesSelfhostSpineCoverageSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_SPINE_COVERAGE_SYNC_GATE="${SELFHOST_SPINE_COVERAGE_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsSelfhostSpineCoverageSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_spine_coverage_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-spine-coverage-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_SPINE_COVERAGE_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesSelfhostSpineCoverageSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_SPINE_COVERAGE_SYNC_GATE=${SELFHOST_SPINE_COVERAGE_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsSelfhostSpineCoverageSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_SPINE_COVERAGE_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-spine-coverage-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_SPINE_COVERAGE_SYNC_GATE` \| `1` \|/', $doc);
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

    public function testCiDefaultsEnvDefinesBootstrapM5DocSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_M5_DOC_SYNC_GATE="${BOOTSTRAP_M5_DOC_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsBootstrapM5DocSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m5_doc_sync_check', $common);
        $this->assertStringContainsString('check-bootstrap-m5-doc-sync.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_M5_DOC_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesBootstrapM5DocSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_M5_DOC_SYNC_GATE=${BOOTSTRAP_M5_DOC_SYNC_GATE:-1}', $body);
    }

    public function testCiDefaultsEnvDefinesSelfhostM4Gen2SyncGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE="${SELFHOST_M4_GEN2_SYNC_GATE:-0}"', $defaults);
    }

    public function testCiFastRunsSelfhostM4Gen2SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_m4_gen2_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-m4-gen2-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesSelfhostM4Gen2SyncGateDefaultOff(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE=${SELFHOST_M4_GEN2_SYNC_GATE:-0}', $body);
    }

    public function testLocalCiMatrixDocumentsSelfhostM4Gen2SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-m4-gen2-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_M4_GEN2_SYNC_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2115', $doc);
    }

    public function testCiDefaultsEnvDefinesBootstrapM3StrictSyncGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_M3_STRICT_SYNC_GATE="${BOOTSTRAP_M3_STRICT_SYNC_GATE:-0}"', $defaults);
    }

    public function testCiFastRunsBootstrapM3StrictSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict_sync_check', $common);
        $this->assertStringContainsString('check-bootstrap-m3-strict-sync.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_STRICT_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesBootstrapM3StrictSyncGateDefaultOff(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_STRICT_SYNC_GATE=${BOOTSTRAP_M3_STRICT_SYNC_GATE:-0}', $body);
    }

    public function testLocalCiMatrixDocumentsBootstrapM3StrictSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_STRICT_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-bootstrap-m3-strict-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_M3_STRICT_SYNC_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2176', $doc);
    }

    public function testLocalCiMatrixDocumentsBootstrapM3CompileSmokeGates(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE', $doc);
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE` \| `1` \|/', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE` \| `0` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesBootstrapVendorInventorySyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE="${BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsBootstrapVendorInventorySyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_vendor_inventory_sync_check', $common);
        $this->assertStringContainsString('bootstrap-vendor-inventory.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesBootstrapVendorInventorySyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE=${BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE:-1}', $body);
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
        $this->assertStringNotContainsString('ci_run_bootstrap_loop_probe', $local);
        $this->assertStringContainsString('ci_run_bootstrap_m4_loop_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('ci_run_bootstrap_m4_loop_probe', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE:-0', $common);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $common);
        $this->assertStringContainsString('--dry-run', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LOOP_PROBE_GATE="${BOOTSTRAP_LOOP_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString(
            'BOOTSTRAP_M4_LOOP_PROBE="${BOOTSTRAP_M4_LOOP_PROBE:-0}"',
            $defaults
        );
    }

    public function testLocalCiMatrixDocumentsBootstrapLoopProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $doc);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $doc);
        $this->assertStringContainsString('bootstrap-loop-probe.sh --dry-run', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE=1', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE=1', $docSelfhost);
        $this->assertStringContainsString('ci-fast.sh', $doc);
        $this->assertStringContainsString('#1929', $doc);
        $this->assertStringContainsString('#2058', $doc);
    }

    public function testCiFastHonorsNorthStar2VerifyGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_north_star2_verify', $fast);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE=1', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $common);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE:-1', $common);
        $this->assertStringContainsString('north-star2-verify.sh', $common);
        $this->assertFileExists(dirname(__DIR__, 2).'/script/north-star2-verify.sh');

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'NORTH_STAR2_VERIFY_GATE="${NORTH_STAR2_VERIFY_GATE:-1}"',
            $defaults
        );
    }

    public function testLocalCiMatrixDocumentsNorthStar2VerifyGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $doc);
        $this->assertStringContainsString('north-star2-verify.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR2_VERIFY_GATE` \| `1` \|/', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE=0', $docSelfhost);
        $this->assertStringContainsString('#2051', $docSelfhost);
        $this->assertStringContainsString('#1928', $doc);
    }

    public function testCiFastHonorsBootstrapTestSubsetGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_bootstrap_test_subset', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_GATE:-0', $common);
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_STRICT', $common);
        $this->assertStringContainsString('bootstrap-test-subset.sh', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_TEST_SUBSET_GATE="${BOOTSTRAP_TEST_SUBSET_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString(
            'BOOTSTRAP_TEST_SUBSET_STRICT="${BOOTSTRAP_TEST_SUBSET_STRICT:-0}"',
            $defaults
        );
    }

    public function testLocalCiMatrixDocumentsBootstrapTestSubsetGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_TEST_SUBSET_GATE', $doc);
        $this->assertStringContainsString('bootstrap-test-subset.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_TEST_SUBSET_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2069', $doc);
    }

    public function testBootstrapTestSubsetScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/bootstrap-test-subset.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('ci_run_selfhost_spine_count_sync_check', $body);
        $this->assertStringContainsString('ci_ensure_generated_doc script/bootstrap-inventory.php', $body);
    }

    public function testLocalCiMatrixDocumentsPhpcTestBootstrap(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('phpc test --bootstrap', $doc);
        $this->assertStringContainsString('bootstrap-test-subset.sh', $doc);
        $this->assertStringContainsString('#1961', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('phpc test --bootstrap', $docSelfhost);
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

    public function testCiDefaultsEnvDefinesCompilerDriverSmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'COMPILER_DRIVER_SMOKE_GATE="${COMPILER_DRIVER_SMOKE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2136', $defaults);
    }

    public function testCiLocalHonorsCompilerDriverSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_compiler_driver_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $common);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $common);
    }

    public function testLocalCiMatrixDocumentsCompilerDriverSmokeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('compiler_driver_smoke bundle OK', $docSelfhost);
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

    public function testLocalCiMatrixDocumentsRebuildExamples005SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('REBUILD_EXAMPLES_005_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-rebuild-examples-005-row.php', $doc);
        $this->assertMatchesRegularExpression('/\| `REBUILD_EXAMPLES_005_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsRebuildExamples006SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('REBUILD_EXAMPLES_006_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-rebuild-examples-006-row.php', $doc);
        $this->assertMatchesRegularExpression('/\| `REBUILD_EXAMPLES_006_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsCapabilities006SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('CAPABILITIES_006_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-capabilities-fileuploadweb-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `CAPABILITIES_006_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsCapabilitiesThrowsSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('CAPABILITIES_THROWS_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-capabilities-throws-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `CAPABILITIES_THROWS_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsBootstrapVendorInventorySyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE', $doc);
        $this->assertStringContainsString('bootstrap-vendor-inventory.php', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsRootReadmeSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-root-readme-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesRootReadme006SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ROOT_README_006_SYNC_GATE="${ROOT_README_006_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsRootReadme006SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_root_readme_006_sync_check', $common);
        $this->assertStringContainsString('ROOT_README_006_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesRootReadme006SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ROOT_README_006_SYNC_GATE=${ROOT_README_006_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsRootReadme006SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_006_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_006_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesRootReadme007SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ROOT_README_007_SYNC_GATE="${ROOT_README_007_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsRootReadme007SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_root_readme_007_sync_check', $common);
        $this->assertStringContainsString('ROOT_README_007_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesRootReadme007SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ROOT_README_007_SYNC_GATE=${ROOT_README_007_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsRootReadme007SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_007_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_007_SYNC_GATE` \| `1` \|/', $doc);
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

    public function testLocalCiMatrixDocumentsSessionsWebGates(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('## 005-SessionsWeb gates', $doc);
        $this->assertStringContainsString('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $doc);
        $this->assertStringContainsString('examples-aot-smoke.sh', $doc);
        $this->assertStringContainsString('#1969', $doc);
    }

    public function testLocalCiMatrixDocumentsThrowsWebGates(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('## 007-ThrowsWeb gates', $doc);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $doc);
        $this->assertStringContainsString('THROWSWEB_AOT_LINK_GATE', $doc);
        $this->assertStringContainsString('THROWSWEB_AOT_SMOKE_GATE', $doc);
        $this->assertStringContainsString('test007ThrowsWebAotLink', $doc);
        $this->assertStringContainsString('ThrowsWebAotExecuteTest', $doc);
        $this->assertStringContainsString('#2102', $doc);
        $this->assertStringContainsString('ci_run_aot_link_phpunit', $doc);
        $this->assertStringContainsString('ci_run_throws_web_aot_execute', $doc);
        $this->assertStringContainsString('#2178', $doc);
        $this->assertStringContainsString('#2157', $doc);
    }

    public function testCiDefaultsEnvDefinesThrowsWebAotGatesOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('THROWSWEB_AOT_LINK_GATE="${THROWSWEB_AOT_LINK_GATE:-1}"', $defaults);
        $this->assertStringContainsString('THROWSWEB_AOT_SMOKE_GATE="${THROWSWEB_AOT_SMOKE_GATE:-1}"', $defaults);
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

    public function testCiDefaultsEnvDefinesRehashComplianceGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'REHASH_COMPLIANCE_GATE="${REHASH_COMPLIANCE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1956', $defaults);
    }

    public function testCiFastRunsRehashComplianceGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('REHASH_COMPLIANCE_GATE', $fast);
        $this->assertStringContainsString('REHASH_COMPLIANCE_GATE:-1', $fast);
        $this->assertStringContainsString('--filter array_rehash_string_keys', $fast);
    }

    public function testLocalCiMatrixDocumentsRehashComplianceGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('REHASH_COMPLIANCE_GATE', $doc);
        $this->assertStringContainsString('array_rehash_string_keys', $doc);
        $this->assertMatchesRegularExpression('/\| `REHASH_COMPLIANCE_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesRehashComplianceGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('REHASH_COMPLIANCE_GATE', $body);
    }

    public function testCiDefaultsEnvDefinesCoalesceComplianceGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'COALESCE_COMPLIANCE_GATE="${COALESCE_COMPLIANCE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1960', $defaults);
    }

    public function testCiFastRunsCoalesceComplianceGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('COALESCE_COMPLIANCE_GATE', $fast);
        $this->assertStringContainsString('COALESCE_COMPLIANCE_GATE:-1', $fast);
        $this->assertStringContainsString('--filter Coalesce', $fast);
    }

    public function testLocalCiMatrixDocumentsCoalesceComplianceGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('COALESCE_COMPLIANCE_GATE', $doc);
        $this->assertStringContainsString('Coalesce*', $doc);
        $this->assertMatchesRegularExpression('/\| `COALESCE_COMPLIANCE_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesCoalesceComplianceGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('COALESCE_COMPLIANCE_GATE', $body);
    }

    public function testCiDefaultsEnvDefinesJitVariableFunctionComplianceGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE="${JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2060', $defaults);
    }

    public function testCiCommonDefinesJitVariableFunctionComplianceRunner(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_jit_variable_function_compliance()', $common);
        $this->assertStringContainsString('JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE:-1', $common);
        $this->assertStringContainsString('--filter VariableFunction', $common);
    }

    public function testCiFastRunsJitVariableFunctionComplianceGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_jit_variable_function_compliance', $fast);
    }

    public function testCiLocalRunsJitVariableFunctionComplianceAfterJitGroup(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_jit_variable_function_compliance', $local);
    }

    public function testLocalCiMatrixDocumentsJitVariableFunctionComplianceGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE', $doc);
        $this->assertStringContainsString('VariableFunction*', $doc);
        $this->assertMatchesRegularExpression('/\| `JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesJitVariableFunctionComplianceGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('JIT_VARIABLE_FUNCTION_COMPLIANCE_GATE', $body);
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
        $this->assertStringContainsString('ci_run_init_miniwebapp_parity_check', $common);
        $this->assertStringContainsString('check-init-miniwebapp-parity.sh', $common);
        $this->assertStringContainsString('INIT_MINIWEBAPP_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesMiniwebappInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('INIT_MINIWEBAPP_PARITY_GATE="${INIT_MINIWEBAPP_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsMiniwebappInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('INIT_MINIWEBAPP_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-miniwebapp-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `INIT_MINIWEBAPP_PARITY_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesMiniwebappInitParityGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('INIT_MINIWEBAPP_PARITY_GATE=${INIT_MINIWEBAPP_PARITY_GATE:-1}', $body);
    }

    public function testCheckMiniwebappLintZeroScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-miniwebapp-lint-zero.php';
        $this->assertFileExists($check);
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/003-MiniWebApp', $body);
        $this->assertStringContainsString('lint --all', $body);
    }

    public function testCiInventoryRunsMiniwebappLintZeroCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_lint_zero_check', $common);
        $this->assertStringContainsString('check-miniwebapp-lint-zero.php', $common);
        $this->assertStringContainsString('MINIWEBAPP_LINT_ZERO_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesMiniwebappLintZeroGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('MINIWEBAPP_LINT_ZERO_GATE="${MINIWEBAPP_LINT_ZERO_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsMiniwebappLintZeroGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('MINIWEBAPP_LINT_ZERO_GATE', $doc);
        $this->assertStringContainsString('check-miniwebapp-lint-zero.php', $doc);
        $this->assertMatchesRegularExpression('/\| `MINIWEBAPP_LINT_ZERO_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesMiniwebappLintZeroGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('MINIWEBAPP_LINT_ZERO_GATE=${MINIWEBAPP_LINT_ZERO_GATE:-1}', $body);
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

    public function testCheckInitFileuploadParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-fileupload-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/006-FileUploadWeb', $body);
        $this->assertStringContainsString('templates/init-fileupload', $body);
    }

    public function testCheckInitFileuploadParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-fileupload-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitFileuploadParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_fileupload_parity_check', $common);
        $this->assertStringContainsString('check-init-fileupload-parity.sh', $common);
        $this->assertStringContainsString('INIT_FILEUPLOAD_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesFileuploadInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('INIT_FILEUPLOAD_PARITY_GATE="${INIT_FILEUPLOAD_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsFileuploadInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('INIT_FILEUPLOAD_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-fileupload-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `INIT_FILEUPLOAD_PARITY_GATE` \| `1` \|/', $doc);
    }

    public function testCheckInitThrowswebParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-throwsweb-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/007-ThrowsWeb', $body);
        $this->assertStringContainsString('templates/init-throwsweb', $body);
    }

    public function testCheckInitThrowswebParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-throwsweb-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitThrowswebParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_throwsweb_parity_check', $common);
        $this->assertStringContainsString('check-init-throwsweb-parity.sh', $common);
        $this->assertStringContainsString('INIT_THROWSWEB_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesThrowswebInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('INIT_THROWSWEB_PARITY_GATE="${INIT_THROWSWEB_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsThrowswebInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('INIT_THROWSWEB_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-throwsweb-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `INIT_THROWSWEB_PARITY_GATE` \| `1` \|/', $doc);
    }

    public function testCheckInitApiJsonParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-apijson-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/004-ApiJson', $body);
        $this->assertStringContainsString('templates/init-apijson', $body);
    }

    public function testCheckInitApiJsonParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-apijson-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitApiJsonParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_apijson_parity_check', $common);
        $this->assertStringContainsString('check-init-apijson-parity.sh', $common);
        $this->assertStringContainsString('APIJSON_INIT_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesApijsonInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('APIJSON_INIT_PARITY_GATE="${APIJSON_INIT_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsApijsonInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('APIJSON_INIT_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-apijson-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `APIJSON_INIT_PARITY_GATE` \| `1` \|/', $doc);
    }
}
