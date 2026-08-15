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

    public function testCiLocalWiresSessionsAndFileUploadServeAotSmokes(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_sessions_web_serve_aot_smoke', $local);
        $this->assertStringContainsString('ci_run_file_upload_web_serve_aot_smoke', $local);
        $this->assertStringContainsString('ci_run_throws_web_serve_aot_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('SESSIONS_WEB_SERVE_AOT_SMOKE_GATE', $common);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE', $common);
        $this->assertStringContainsString('THROWSWEB_SERVE_AOT_SMOKE_GATE', $common);
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE', $common);
        $this->assertStringContainsString('ci_run_throws_web_serve_jit_smoke', $common);
        $this->assertStringContainsString('--sessions-only --aot', $common);
        $this->assertStringContainsString('--fileupload-only --aot', $common);
        $this->assertStringContainsString('--throws-only --aot', $common);
        $this->assertStringContainsString('--throws-only --jit', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SESSIONS_WEB_SERVE_AOT_SMOKE_GATE="${SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString(
            'FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE="${FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString(
            'THROWSWEB_SERVE_AOT_SMOKE_GATE="${THROWSWEB_SERVE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString(
            'THROWSWEB_SERVE_JIT_SMOKE_GATE="${THROWSWEB_SERVE_JIT_SMOKE_GATE:-1}"',
            $defaults
        );
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

    public function testCiDefaultsEnvDefinesSessionsWebAotSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE="${SESSIONS_WEB_AOT_SMOKE_GATE:-1}"', $defaults);
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
        $this->assertStringContainsString('SESSIONS_WEB_AOT_SMOKE_GATE:-1', $body);
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
        $this->assertStringContainsString('ci_run_throws_web_uncaught_smoke', $body);
        $this->assertStringContainsString('ci_run_throws_web_serve_jit_smoke', $body);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $common);
        $this->assertStringContainsString('THROWSWEB_UNCAUGHT_500_GATE', $common);
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE', $common);
        $this->assertStringContainsString('--throws-only', $common);
    }

    public function testCiDefaultsEnvDefinesThrowsWebUncaught500GateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'THROWSWEB_UNCAUGHT_500_GATE="${THROWSWEB_UNCAUGHT_500_GATE:-0}"',
            $defaults
        );
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
        $this->assertStringContainsString('THROWSWEB_UNCAUGHT_500_GATE', $smoke);
        $this->assertStringContainsString('curl_expect_500', $smoke);
        $this->assertStringContainsString('uncaught.php', $smoke);
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

    public function testCiFastRunsFastcgiWebSmokeWhenGateOn(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_fastcgi_web_smoke', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('FASTCGI_WEB_SMOKE_GATE', $common);
        $this->assertStringContainsString('--fastcgi-only', $common);
        $this->assertStringContainsString('009-FastCGIWeb', $common);
    }

    public function testCiDefaultsEnvDefinesFastcgiWebSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'FASTCGI_WEB_SMOKE_GATE="${FASTCGI_WEB_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiDefaultsEnvDefinesSelfhostprobeAotSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SELFHOSTPROBE_AOT_SMOKE_GATE="${SELFHOSTPROBE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiLocalRunsSelfhostprobeAotSmoke(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_selfhostprobe_aot_smoke', $local);
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('--group selfhostprobe-aot-execute', $common);
        $this->assertStringContainsString('--exclude-group selfhostprobe-aot-execute', $common);
    }

    public function testCiDefaultsEnvDefinesFastcgiWebAotSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('FASTCGI_WEB_AOT_SMOKE_GATE="${FASTCGI_WEB_AOT_SMOKE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('FASTCGI_SMOKE_GATE="${FASTCGI_SMOKE_GATE:-0}"', $defaults);
    }

    public function testCiLocalExcludesFastcgiSmokeUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_fastcgi_smoke', $body);
        $this->assertStringContainsString('FASTCGI_SMOKE_GATE:-0', $body);
        $this->assertStringContainsString('fastcgi-smoke.sh', $body);
    }

    public function testCiLocalRunsFastcgiSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_fastcgi_smoke', $local);
    }

    public function testCiLocalExcludesFastcgiWebAotExecuteUnlessGateOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_fastcgi_web_aot_execute', $body);
        $this->assertStringContainsString('--exclude-group fastcgiweb-aot-execute', $body);
        $this->assertStringContainsString('--group fastcgiweb-aot-execute', $body);
        $this->assertStringContainsString('FASTCGI_WEB_AOT_SMOKE_GATE:-1', $body);
    }

    public function testCiLocalRunsFastcgiWebAotExecute(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_fastcgi_web_aot_execute', $local);
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
        $this->assertStringContainsString('005-SessionsWeb', $body);
        $this->assertStringContainsString('006-FileUploadWeb', $body);
        $this->assertStringContainsString('007-ThrowsWeb', $body);
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

    public function testReleaseReadinessScriptExistsAndJsonSchema(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $script = $repoRoot.'/script/release-readiness.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('--full', $body);
        $this->assertStringContainsString('--json', $body);
        $this->assertStringContainsString('user_release_ready', $body);
        $this->assertStringContainsString('run_gate_bootstrap_inventory', $body);
        $this->assertStringContainsString('check-helper-runtime-prelink.php --strict', $body);
        $this->assertStringContainsString('ci_ensure_generated_doc', $body);
        $this->assertStringContainsString('script/bootstrap-inventory.php', $body);
        $this->assertStringContainsString('release-readiness-json-emit.php', $body);
        $this->assertStringContainsString('^OK [0-9]+/[0-9]+$', $body);
        $this->assertStringContainsString('check-selfhost-spine-coverage-sync.php', $body);
        $this->assertStringContainsString('check-root-readme-sync.php', $body);
        $this->assertStringContainsString('north-star5-verify-fast', $body);
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe', $body);
        $this->assertStringContainsString('bootstrap-honest-compile-metric.sh', $body);
        $this->assertStringContainsString('_RR_HONEST_COMPILE_JSON', $body);
        $this->assertStringContainsString('examples-aot-smoke.sh', $body);
        $this->assertStringContainsString('examples-web-smoke.sh', $body);
        $this->assertStringContainsString('(examples-aot-smoke|examples-web-smoke): ok$', $body);
        $this->assertStringContainsString('RELEASE_READINESS_CI_FAST', $body);
        $this->assertStringContainsString('#8737', $body);

        $makefile = (string) file_get_contents($repoRoot.'/Makefile');
        $this->assertStringContainsString('release-readiness:', $makefile);

        $doc = (string) file_get_contents($repoRoot.'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('release-readiness.sh', $doc);
        $this->assertStringContainsString('user_release_ready', $doc);

        $this->assertFileExists($repoRoot.'/CHANGELOG.md');
        $changelog = (string) file_get_contents($repoRoot.'/CHANGELOG.md');
        $this->assertMatchesRegularExpression('/^## v1\.1\.0\b/m', $changelog);

        $cmd = 'env PHP_COMPILER_ALLOW_PARALLEL_CI=1 bash '.escapeshellarg($script).' --json --dry-run 2>/dev/null';
        $raw = shell_exec($cmd);
        $this->assertIsString($raw);
        $this->assertNotSame('', trim($raw));
        if (!preg_match('/\{.*\}/s', $raw, $jsonMatch)) {
            $this->fail('release-readiness --json --dry-run did not emit JSON object');
        }
        $payload = json_decode($jsonMatch[0], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('user_release_ready', $payload);
        $this->assertContains($payload['user_release_ready'], ['yes', 'no']);
        $this->assertSame('quick', $payload['mode'] ?? null);
        $this->assertArrayHasKey('gates', $payload);
        $this->assertIsArray($payload['gates']);
        $this->assertNotEmpty($payload['gates']);
        $this->assertArrayHasKey('honest_compile', $payload);
        $this->assertIsArray($payload['honest_compile']);
        $this->assertArrayHasKey('status', $payload['honest_compile']);
        $this->assertArrayHasKey('message', $payload['honest_compile']);
        $this->assertArrayHasKey('gate_available', $payload['honest_compile']);
        foreach ($payload['gates'] as $gate) {
            $this->assertArrayHasKey('name', $gate);
            $this->assertArrayHasKey('status', $gate);
            $this->assertArrayHasKey('message', $gate);
        }
    }

    public function testReleaseReadinessJsonPreservesEmptyGateMessages(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $emit = $repoRoot.'/script/release-readiness-json-emit.php';
        $this->assertFileExists($emit);

        $env = [
            '_RR_MODE' => 'quick',
            '_RR_READY' => 'no',
            '_RR_GATE_COUNT' => '3',
            '_RR_GATE_NAME_0' => 'bootstrap-inventory',
            '_RR_GATE_STATUS_0' => 'ok',
            '_RR_GATE_MESSAGE_0' => 'OK 2867/2867',
            '_RR_GATE_NAME_1' => 'north-star5-fast',
            '_RR_GATE_STATUS_1' => 'ok',
            '_RR_GATE_MESSAGE_1' => '',
            '_RR_GATE_NAME_2' => 'capability-matrix',
            '_RR_GATE_STATUS_2' => 'fail',
            '_RR_GATE_MESSAGE_2' => 'docs/capabilities.md is out of date',
        ];
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }
        $raw = shell_exec($prefix.'php '.escapeshellarg($emit).' 2>/dev/null');
        $this->assertIsString($raw);
        $payload = json_decode($raw, true);
        $this->assertIsArray($payload);
        $this->assertCount(3, $payload['gates']);
        $this->assertSame('bootstrap-inventory', $payload['gates'][0]['name']);
        $this->assertSame('OK 2867/2867', $payload['gates'][0]['message']);
        $this->assertSame('north-star5-fast', $payload['gates'][1]['name']);
        $this->assertSame('', $payload['gates'][1]['message']);
        $this->assertSame('capability-matrix', $payload['gates'][2]['name']);
        $this->assertSame('fail', $payload['gates'][2]['status']);
        $this->assertSame('docs/capabilities.md is out of date', $payload['gates'][2]['message']);
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
        $this->assertStringContainsString('009-FastCGIWeb', $body);
        $this->assertStringContainsString('FASTCGI_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('smoke_009_fastcgi_web', $body);

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

    public function testMakefileHasExamplesThrowsJitSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-throws-jit-smoke:', $makefile);
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE=1', $makefile);
        $this->assertStringContainsString('examples-web-smoke.sh --throws-only --jit', $makefile);
        $this->assertStringContainsString('#2408', $makefile);
    }

    public function testMakefileHasExamplesFastcgiwebSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-fastcgiweb-smoke:', $makefile);
        $this->assertStringContainsString('FASTCGI_WEB_SMOKE_GATE=1', $makefile);
        $this->assertStringContainsString('examples-web-smoke.sh --fastcgi-only', $makefile);
    }

    public function testMakefileHasExamplesServeJitSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-serve-jit-smoke:', $makefile);
        $this->assertStringContainsString('examples-serve-jit-smoke.sh', $makefile);
        $this->assertStringContainsString('SERVE_JIT_SMOKE_GATE=1', $makefile);

        $script = dirname(__DIR__, 2).'/script/examples-serve-jit-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('007-ThrowsWeb', $body);
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE', $body);
        $this->assertStringContainsString('#2478', $body);
    }

    public function testCiDefaultsEnvDefinesServeJitSmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SERVE_JIT_SMOKE_GATE="${SERVE_JIT_SMOKE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2274', $defaults);
    }

    public function testCiCommonDefinesServeJitSmokeRunner(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_examples_serve_jit_smoke()', $common);
        $this->assertStringContainsString('SERVE_JIT_SMOKE_GATE', $common);
        $this->assertStringContainsString('examples-serve-jit-smoke.sh', $common);
    }

    public function testCiLocalRunsServeJitSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_examples_serve_jit_smoke', $local);
    }

    public function testMakefileHasExamplesSelfhostprobeSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-selfhostprobe-smoke:', $makefile);
        $this->assertStringContainsString('examples-selfhostprobe-smoke.sh', $makefile);

        $script = dirname(__DIR__, 2).'/script/examples-selfhostprobe-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
    }

    public function testCiDefaultsEnvDefinesExamplesSelfhostprobeSmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'EXAMPLES_SELFHOSTPROBE_SMOKE_GATE="${EXAMPLES_SELFHOSTPROBE_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiFastRunsExamplesSelfhostprobeSmokeGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_examples_selfhostprobe_smoke', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('EXAMPLES_SELFHOSTPROBE_SMOKE_GATE', $common);
        $this->assertStringContainsString('EXAMPLES_SELFHOSTPROBE_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('examples-selfhostprobe-smoke.sh', $common);
        $this->assertStringContainsString('EXAMPLES_SELFHOSTPROBE_SMOKE_GATE=0', $common);
    }

    public function testLocalCiMatrixDocumentsExamplesSelfhostprobeSmokeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('EXAMPLES_SELFHOSTPROBE_SMOKE_GATE', $doc);
        $this->assertStringContainsString('examples-selfhostprobe-smoke.sh', $doc);
        $this->assertStringContainsString('#2343', $doc);
        $this->assertMatchesRegularExpression('/\| `EXAMPLES_SELFHOSTPROBE_SMOKE_GATE` \| `1` \|/', $doc);
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

    public function testMakefileHasExamplesThrowsWebDeploySmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-throwsweb-deploy-smoke:', $makefile);
        $this->assertStringContainsString('examples-throwsweb-deploy-smoke.sh', $makefile);

        $script = dirname(__DIR__, 2).'/script/examples-throwsweb-deploy-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('THROWSWEB_DEPLOY_SMOKE_GATE=1', $body);
        $this->assertStringContainsString('deploy-smoke.sh --example 007', $body);
    }

    public function testMakefileHasExamplesFastcgiwebDeploySmokeTarget(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('examples-fastcgiweb-deploy-smoke:', $makefile);
        $this->assertStringContainsString('examples-fastcgiweb-deploy-smoke.sh', $makefile);

        $script = dirname(__DIR__, 2).'/script/examples-fastcgiweb-deploy-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('FASTCGI_WEB_DEPLOY_SMOKE_GATE=1', $body);
        $this->assertStringContainsString('deploy-smoke.sh --example 009', $body);
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
        $this->assertStringContainsString('run_example 007', $body);
        $this->assertStringContainsString('run_example 009', $body);
        $this->assertStringContainsString('SESSIONS_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('THROWSWEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('FASTCGI_WEB_DEPLOY_SMOKE_GATE', $body);
        $this->assertStringContainsString('skip (SESSIONS_WEB_DEPLOY_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('skip (FILE_UPLOAD_WEB_DEPLOY_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('skip (THROWSWEB_DEPLOY_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('skip (FASTCGI_WEB_DEPLOY_SMOKE_GATE=0', $body);
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
        $this->assertStringContainsString('THROWSWEB_DEPLOY_SMOKE_GATE', $common);
        $this->assertStringContainsString('THROWSWEB_DEPLOY_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('--example 007', $common);
        $this->assertStringContainsString('FASTCGI_WEB_DEPLOY_SMOKE_GATE', $common);
        $this->assertStringContainsString('--example 009', $common);
    }

    public function testCiLocalHonorsDeploySmokeAllGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_deploy_smoke_all', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('DEPLOY_SMOKE_ALL_GATE', $common);
        $this->assertStringContainsString('DEPLOY_SMOKE_ALL_GATE:-0', $common);
        $this->assertStringContainsString('deploy-smoke-all.sh', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('DEPLOY_SMOKE_ALL_GATE="${DEPLOY_SMOKE_ALL_GATE:-0}"', $defaults);

        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DEPLOY_SMOKE_ALL_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `DEPLOY_SMOKE_ALL_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2085', $doc);
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

    public function testCiDefaultsEnvDefinesThrowsWebDeploySmokeGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'THROWSWEB_DEPLOY_SMOKE_GATE="${THROWSWEB_DEPLOY_SMOKE_GATE:-1}"',
            $defaults
        );
    }

    public function testCiDefaultsEnvDefinesFastcgiWebDeploySmokeGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'FASTCGI_WEB_DEPLOY_SMOKE_GATE="${FASTCGI_WEB_DEPLOY_SMOKE_GATE:-0}"',
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

    public function testCiDefaultsEnvDefinesRebuildExamples009SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('REBUILD_EXAMPLES_009_SYNC_GATE="${REBUILD_EXAMPLES_009_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiDefaultsEnvDefinesRebuildExamples003JitProjectSyncGateOptIn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE="${REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE:-0}"',
            $defaults
        );
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

    public function testCiFastRunsRebuildExamples009SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_rebuild_examples_009_sync_check', $common);
        $this->assertStringContainsString('check-rebuild-examples-009-sync.php', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_009_SYNC_GATE:-1', $common);
    }

    public function testCiFastRunsRebuildExamples003JitProjectSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_rebuild_examples_003_jit_project_sync_check', $common);
        $this->assertStringContainsString('check-rebuild-examples-003-jit-project-sync.php', $common);
        $this->assertStringContainsString('REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE:-0', $common);
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

    public function testCiDefaultsEnvDefinesCapabilitiesOopSyncGateOptIn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('CAPABILITIES_OOP_SYNC_GATE="${CAPABILITIES_OOP_SYNC_GATE:-0}"', $defaults);
    }

    public function testCiFastRunsCapabilitiesOopSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_capabilities_oop_sync_check', $common);
        $this->assertStringContainsString('check-capabilities-oop-sync.php', $common);
        $this->assertStringContainsString('CAPABILITIES_OOP_SYNC_GATE:-0', $common);
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

    public function testCiDockerRunPassesRebuildExamples009SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('REBUILD_EXAMPLES_009_SYNC_GATE=${REBUILD_EXAMPLES_009_SYNC_GATE:-1}', $body);
    }

    public function testCiDockerRunPassesRebuildExamples003JitProjectSyncGateDefaultOptIn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE=${REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE:-0}',
            $body
        );
    }

    public function testCiDockerRunPassesCapabilities006SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('CAPABILITIES_006_SYNC_GATE=${CAPABILITIES_006_SYNC_GATE:-1}', $body);
        $this->assertStringContainsString('CAPABILITIES_THROWS_SYNC_GATE=${CAPABILITIES_THROWS_SYNC_GATE:-1}', $body);
        $this->assertStringContainsString('CAPABILITIES_OOP_SYNC_GATE=${CAPABILITIES_OOP_SYNC_GATE:-0}', $body);
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

    public function testCiDefaultsEnvDefinesSelfhostSpineDeferredSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_SPINE_DEFERRED_SYNC_GATE="${SELFHOST_SPINE_DEFERRED_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsSelfhostSpineDeferredSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_spine_deferred_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-spine-deferred-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_SPINE_DEFERRED_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesSelfhostSpineDeferredSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_SPINE_DEFERRED_SYNC_GATE=${SELFHOST_SPINE_DEFERRED_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsSelfhostSpineDeferredSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_SPINE_DEFERRED_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-spine-deferred-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_SPINE_DEFERRED_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2202', $doc);
    }

    public function testCiDefaultsEnvDefinesSelfhostSpineSidecarSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_SPINE_SIDECAR_SYNC_GATE="${SELFHOST_SPINE_SIDECAR_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsSelfhostSpineSidecarSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_spine_sidecar_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-spine-sidecar-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_SPINE_SIDECAR_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesSelfhostSpineSidecarSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_SPINE_SIDECAR_SYNC_GATE=${SELFHOST_SPINE_SIDECAR_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsSelfhostSpineSidecarSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_SPINE_SIDECAR_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-spine-sidecar-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_SPINE_SIDECAR_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#8703', $doc);
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

    public function testCiDefaultsEnvDefinesBootstrapInventoryLintSyncGateOptIn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE="${BOOTSTRAP_INVENTORY_LINT_SYNC_GATE:-0}"', $defaults);
    }

    public function testCiFastRunsBootstrapInventoryLintSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_inventory_lint_sync_check', $common);
        $this->assertStringContainsString('check-bootstrap-inventory-lint-sync.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesBootstrapInventoryLintSyncGateOptIn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE=${BOOTSTRAP_INVENTORY_LINT_SYNC_GATE:-0}', $body);
    }

    public function testLocalCiMatrixDocumentsBootstrapInventoryLintSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_LINT_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-bootstrap-inventory-lint-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_INVENTORY_LINT_SYNC_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2210', $doc);
    }

    public function testCiDefaultsEnvDefinesBootstrapInventoryTriageSyncGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE="${BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsBootstrapInventoryTriageSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_bootstrap_inventory_triage_sync_check', $common);
        $this->assertStringContainsString('check-bootstrap-inventory-triage-sync.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesBootstrapInventoryTriageSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE=${BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsBootstrapInventoryTriageSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-bootstrap-inventory-triage-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_INVENTORY_TRIAGE_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2265', $doc);
        $this->assertStringContainsString('#2389', $doc);
    }

    public function testCiDefaultsEnvDefinesStdlibJitDeferredSyncGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('STDLIB_JIT_DEFERRED_SYNC_GATE="${STDLIB_JIT_DEFERRED_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsStdlibJitDeferredSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_stdlib_jit_deferred_sync_check', $common);
        $this->assertStringContainsString('check-stdlib-jit-deferred-sync.php', $common);
        $this->assertStringContainsString('STDLIB_JIT_DEFERRED_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesStdlibJitDeferredSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('STDLIB_JIT_DEFERRED_SYNC_GATE=${STDLIB_JIT_DEFERRED_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsStdlibJitDeferredSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('STDLIB_JIT_DEFERRED_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-stdlib-jit-deferred-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `STDLIB_JIT_DEFERRED_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2465', $doc);
    }

    public function testCiDefaultsEnvDefinesDoctorGatesMatrixSyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('DOCTOR_GATES_MATRIX_SYNC_GATE="${DOCTOR_GATES_MATRIX_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsDoctorGatesMatrixSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_doctor_gates_matrix_sync_check', $common);
        $this->assertStringContainsString('check-doctor-gates-sync.php', $common);
        $this->assertStringContainsString('DOCTOR_GATES_MATRIX_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesDoctorGatesMatrixSyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('DOCTOR_GATES_MATRIX_SYNC_GATE=${DOCTOR_GATES_MATRIX_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsDoctorGatesMatrixSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DOCTOR_GATES_MATRIX_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-doctor-gates-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `DOCTOR_GATES_MATRIX_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesDocsHarnessHygieneGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('DOCS_HARNESS_HYGIENE_GATE="${DOCS_HARNESS_HYGIENE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('#2485', $defaults);
    }

    public function testCiFastRunsDocsHarnessHygieneViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_docs_harness_hygiene_check', $common);
        $this->assertStringContainsString('check-docs-harness-hygiene.php', $common);
        $this->assertStringContainsString('DOCS_HARNESS_HYGIENE_GATE:-1', $common);
    }

    public function testCiDockerRunPassesDocsHarnessHygieneGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('DOCS_HARNESS_HYGIENE_GATE=${DOCS_HARNESS_HYGIENE_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsDocsHarnessHygieneGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DOCS_HARNESS_HYGIENE_GATE', $doc);
        $this->assertStringContainsString('check-docs-harness-hygiene.php', $doc);
        $this->assertMatchesRegularExpression('/\| `DOCS_HARNESS_HYGIENE_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2485', $doc);
    }

    public function testCiDefaultsEnvDefinesSelfhostM4Gen2SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE="${SELFHOST_M4_GEN2_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsSelfhostM4Gen2SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_selfhost_m4_gen2_sync_check', $common);
        $this->assertStringContainsString('check-selfhost-m4-gen2-sync.php', $common);
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesSelfhostM4Gen2SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE=${SELFHOST_M4_GEN2_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsSelfhostM4Gen2SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('SELFHOST_M4_GEN2_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-selfhost-m4-gen2-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `SELFHOST_M4_GEN2_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2115', $doc);
        $this->assertStringContainsString('#2175', $doc);
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
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE` \| `1` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesBootstrapM3CompileSmokeStrictGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE="${BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE:-1}"', $defaults);
    }

    public function testCiDockerRunPassesBootstrapM3CompileSmokeStrictGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE=${BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE:-1}', $body);
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
        $this->assertStringContainsString('bootstrap-vendor-objects.php', $common);
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_INVENTORY_SYNC_GATE:-0', $common);
        $this->assertStringContainsString('BOOTSTRAP_VENDOR_PRELINK_SYNC_GATE:-0', $common);
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
        $this->assertStringContainsString('ci_run_bootstrap_m4_full_spine_probe', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $common);
        $this->assertStringContainsString('full ladder, issue #1498, #2780', $common);
        $m4Start = strpos($common, 'ci_run_bootstrap_m4_loop_probe()');
        $m4End = strpos($common, 'ci_run_bootstrap_m4_full_spine_probe()');
        $this->assertNotFalse($m4Start);
        $this->assertNotFalse($m4End);
        $m4Block = substr($common, $m4Start, $m4End - $m4Start);
        $this->assertStringNotContainsString('--dry-run', $m4Block);
        $this->assertStringContainsString('BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-loop-probe.sh', $common);
        $this->assertStringContainsString('bootstrap-loop-full-spine-probe.sh', $common);
        $this->assertStringContainsString('--dry-run', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_LOOP_PROBE_GATE="${BOOTSTRAP_LOOP_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString(
            'BOOTSTRAP_M4_LOOP_PROBE="${BOOTSTRAP_M4_LOOP_PROBE:-1}"',
            $defaults
        );
        $this->assertStringContainsString(
            'BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE="${BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE:-0}"',
            $defaults
        );
    }

    public function testLocalCiMatrixDocumentsBootstrapLoopProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE', $doc);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE', $doc);
        $this->assertStringContainsString('| `BOOTSTRAP_M4_LOOP_PROBE` | `1` |', $doc);
        $this->assertStringContainsString('#2780', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_LOOP_PROBE_GATE=1', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_M4_LOOP_PROBE=0', $docSelfhost);
        $this->assertStringContainsString('#2780', $docSelfhost);
        $this->assertStringContainsString('bootstrap-loop-full-spine-probe', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_M4_FULL_SPINE_PROBE_GATE=1', $docSelfhost);
        $this->assertStringContainsString('ci-fast.sh', $doc);
        $this->assertStringContainsString('#1929', $doc);
        $this->assertStringContainsString('#2058', $doc);
    }

    public function testCiFastHonorsNorthStar2VerifyGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_north_star2_verify', $fast);
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE=0', $fast);

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
        $this->assertStringContainsString(
            'NORTH_STAR2_THROWSWEB_GATE="${NORTH_STAR2_THROWSWEB_GATE:-1}"',
            $defaults
        );

        $docker = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'NORTH_STAR2_THROWSWEB_GATE=${NORTH_STAR2_THROWSWEB_GATE:-1}',
            $docker
        );
    }

    public function testLocalCiMatrixDocumentsNorthStar2VerifyGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE', $doc);
        $this->assertStringContainsString('north-star2-verify.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR2_VERIFY_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('NORTH_STAR2_THROWSWEB_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR2_THROWSWEB_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('examples-throws-smoke', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('NORTH_STAR2_VERIFY_GATE=0', $docSelfhost);
        $this->assertStringContainsString('#2051', $docSelfhost);
        $this->assertStringContainsString('#1928', $doc);
    }

    public function testCiFastHonorsNorthStar3VerifyGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_north_star3_verify', $fast);
        $this->assertStringContainsString('NORTH_STAR3_VERIFY_GATE=1', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('NORTH_STAR3_VERIFY_GATE', $common);
        $this->assertStringContainsString('NORTH_STAR3_VERIFY_GATE:-0', $common);
        $this->assertStringContainsString('north-star3-verify', $common);
        $this->assertStringContainsString('make -C', $common);
        $this->assertFileExists(dirname(__DIR__, 2).'/script/north-star3-verify.sh');

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'NORTH_STAR3_VERIFY_GATE="${NORTH_STAR3_VERIFY_GATE:-0}"',
            $defaults
        );

        $docker = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'NORTH_STAR3_VERIFY_GATE=${NORTH_STAR3_VERIFY_GATE:-0}',
            $docker
        );
    }

    public function testLocalCiMatrixDocumentsNorthStar3VerifyGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NORTH_STAR3_VERIFY_GATE', $doc);
        $this->assertStringContainsString('north-star3-verify', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR3_VERIFY_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2396', $doc);
    }

    public function testCiLocalHonorsNorthStar4VerifyGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_north_star4_verify', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('NORTH_STAR4_VERIFY_GATE', $common);
        $this->assertStringContainsString('NORTH_STAR4_VERIFY_GATE:-0', $common);
        $this->assertStringContainsString('north-star4-verify', $common);
        $this->assertStringContainsString('make -C', $common);
        $this->assertFileExists(dirname(__DIR__, 2).'/script/north-star4-verify.sh');

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'NORTH_STAR4_VERIFY_GATE="${NORTH_STAR4_VERIFY_GATE:-0}"',
            $defaults
        );

        $docker = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'NORTH_STAR4_VERIFY_GATE=${NORTH_STAR4_VERIFY_GATE:-0}',
            $docker
        );
    }

    public function testLocalCiMatrixDocumentsNorthStar4VerifyGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NORTH_STAR4_VERIFY_GATE', $doc);
        $this->assertStringContainsString('north-star4-verify', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR4_VERIFY_GATE` \| `0` \|/', $doc);
        $this->assertStringContainsString('#2429', $doc);
    }

    public function testCiFastHonorsNorthStar5VerifyFastGate(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_north_star5_verify_fast', $fast);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('NORTH_STAR5_VERIFY_FAST_GATE', $common);
        $this->assertStringContainsString('NORTH_STAR5_VERIFY_FAST_GATE:-1', $common);
        $this->assertStringContainsString('north-star5-verify.sh', $common);
        $this->assertStringContainsString('--fast', $common);
        $this->assertFileExists(dirname(__DIR__, 2).'/script/north-star5-verify.sh');

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'NORTH_STAR5_VERIFY_FAST_GATE="${NORTH_STAR5_VERIFY_FAST_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString(
            'NORTH_STAR5_VERIFY_STRICT_GATE="${NORTH_STAR5_VERIFY_STRICT_GATE:-0}"',
            $defaults
        );

        $docker = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'NORTH_STAR5_VERIFY_FAST_GATE=${NORTH_STAR5_VERIFY_FAST_GATE:-1}',
            $docker
        );
    }

    public function testLocalCiMatrixDocumentsNorthStar5VerifyFastGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('NORTH_STAR5_VERIFY_FAST_GATE', $doc);
        $this->assertStringContainsString('north-star5-verify-fast', $doc);
        $this->assertMatchesRegularExpression('/\| `NORTH_STAR5_VERIFY_FAST_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#1492', $doc);
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
        $this->assertStringContainsString('ci_run_bootstrap_wave_check_vendor_absent', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK', $common);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK:-1', $common);
        $this->assertStringContainsString('bootstrap-wave-check.sh', $common);
        $this->assertStringContainsString('--fail-fast', $common);
        $this->assertStringContainsString('ci_run_bootstrap_wave_check_vendor_absent', $common);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE:-1', $common);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT:-1', $common);
        $this->assertStringContainsString('--vendor-absent', $common);

        $wave = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-wave-check.sh');
        $this->assertStringContainsString('--vendor-absent', $wave);
        $this->assertStringContainsString('VENDOR_TREE_ABSENT=1', $wave);
        $this->assertStringContainsString('wave_check_restore_vendor', $wave);

        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('CI_FAST_BOOTSTRAP', $fast);
        $this->assertStringContainsString('ci_run_bootstrap_wave_check', $fast);
        $this->assertStringContainsString('ci_run_bootstrap_wave_check_vendor_absent', $fast);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE="${BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE:-1}"',
            $defaults
        );
    }

    public function testCiLocalHonorsBootstrapM3StrictGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m3_strict', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT=1', $common);
        $this->assertStringContainsString('BOOTSTRAP_M3_LINK_COMPILE_DRIVER=1', $common);
        $probe = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-selfhost-helloworld-probe.sh');
        $this->assertStringContainsString('block_reason=', $probe);
        $this->assertStringContainsString('NEXT_LOWER', $probe);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE="${BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#1866', $defaults);
    }

    public function testLocalCiMatrixDocumentsBootstrapM3StrictGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-helloworld-probe.sh', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_M3_HELLOWORLD_STRICT_GATE=0', $docSelfhost);
        $this->assertStringContainsString('#1866', $docSelfhost);
    }

    public function testCiLocalHonorsBootstrapM4Gen2StrictGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_m4_gen2_strict', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $common);
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT=1', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_M4_GEN2_STRICT_GATE="${BOOTSTRAP_M4_GEN2_STRICT_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2112', $defaults);
    }

    public function testLocalCiMatrixDocumentsBootstrapM4Gen2StrictGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT_GATE', $doc);
        $this->assertStringContainsString('bootstrap-loop-gen1-link.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `BOOTSTRAP_M4_GEN2_STRICT_GATE` \| `1` \|/', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT_GATE', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_M4_GEN2_STRICT_GATE=0', $docSelfhost);
        $this->assertStringContainsString('#2112', $docSelfhost);
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

    public function testCiDefaultsEnvDefinesBootstrapVmDriverExecuteGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_VM_DRIVER_EXECUTE_GATE="${BOOTSTRAP_VM_DRIVER_EXECUTE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2227', $defaults);
        $this->assertStringContainsString('#2201', $defaults);
    }

    public function testCiLocalHonorsBootstrapVmDriverExecuteGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_vm_driver_execute_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe.sh', $common);
    }

    public function testLocalCiMatrixDocumentsBootstrapVmDriverExecuteGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-vm-driver-execute-probe.sh', $doc);
        $this->assertStringContainsString('#2227', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_GATE=1', $docSelfhost);
        $this->assertStringContainsString('BOOTSTRAP_VM_DRIVER_EXECUTE_GATE=0', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesCompilerDriverSmokeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'COMPILER_DRIVER_SMOKE_GATE="${COMPILER_DRIVER_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2136', $defaults);
        $this->assertStringContainsString('#2137', $defaults);
        $this->assertStringContainsString('#2168', $defaults);
    }

    public function testCiLocalHonorsCompilerDriverSmokeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_compiler_driver_smoke', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $common);
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $common);
    }

    public function testLocalCiMatrixDocumentsCompilerDriverSmokeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('COMPILER_DRIVER_SMOKE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-compiler-driver-smoke-link.sh', $doc);
        $this->assertStringContainsString('#2168', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('compiler_driver_smoke bundle OK', $docSelfhost);
        $this->assertStringContainsString('#2168', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesJitUnitProbeGateDefaultOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_JIT_UNIT_PROBE_GATE="${BOOTSTRAP_JIT_UNIT_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2332', $defaults);
        $this->assertStringContainsString('#2361', $defaults);
    }

    public function testCiLocalHonorsJitUnitProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_jit_unit_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $common);
    }

    public function testLocalCiMatrixDocumentsJitUnitProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_JIT_UNIT_PROBE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-jit-unit-probe.sh', $doc);
        $this->assertStringContainsString('#2361', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('jit_unit_probe bundle OK', $docSelfhost);
        $this->assertStringContainsString('#2361', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesVmUnitProbeGateDefaultOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_VM_UNIT_PROBE_GATE="${BOOTSTRAP_VM_UNIT_PROBE_GATE:-0}"',
            $defaults
        );
        $this->assertStringContainsString('#2354', $defaults);
        $this->assertStringContainsString('#2368', $defaults);
    }

    public function testCiLocalHonorsVmUnitProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_vm_unit_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE:-0', $common);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $common);
    }

    public function testLocalCiMatrixDocumentsVmUnitProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_VM_UNIT_PROBE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-vm-unit-probe.sh', $doc);
        $this->assertStringContainsString('#2368', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('vm_unit_probe bundle OK', $docSelfhost);
        $this->assertStringContainsString('#2368', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesParserUnitProbeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_PARSER_UNIT_PROBE_GATE="${BOOTSTRAP_PARSER_UNIT_PROBE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2409', $defaults);
        $this->assertStringContainsString('#2417', $defaults);
        $this->assertStringContainsString('#2419', $defaults);
    }

    public function testCiLocalHonorsParserUnitProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_parser_unit_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $common);
    }

    public function testLocalCiMatrixDocumentsParserUnitProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_PARSER_UNIT_PROBE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-parser-unit-probe.sh', $doc);
        $this->assertStringContainsString('#2417', $doc);
        $this->assertStringContainsString('#2419', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('parser_unit_probe bundle OK', $docSelfhost);
        $this->assertStringContainsString('#2417', $docSelfhost);
        $this->assertStringContainsString('#2419', $docSelfhost);
    }

    public function testCiDefaultsEnvDefinesPhptypesUnitProbeGateDefaultOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE="${BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2430', $defaults);
        $this->assertStringContainsString('#2433', $defaults);
        $this->assertStringContainsString('#2436', $defaults);
    }

    public function testCiLocalHonorsPhptypesUnitProbeGate(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_bootstrap_phptypes_unit_probe', $local);

        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $common);
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE:-1', $common);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $common);
    }

    public function testLocalCiMatrixDocumentsPhptypesUnitProbeGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('BOOTSTRAP_PHPTYPES_UNIT_PROBE_GATE', $doc);
        $this->assertStringContainsString('bootstrap-selfhost-types-unit-probe.sh', $doc);
        $this->assertStringContainsString('#2433', $doc);
        $this->assertStringContainsString('#2436', $doc);

        $docSelfhost = (string) file_get_contents(dirname(__DIR__, 2).'/docs/bootstrap-selfhost.md');
        $this->assertStringContainsString('types_unit_probe bundle OK', $docSelfhost);
        $this->assertStringContainsString('#2433', $docSelfhost);
        $this->assertStringContainsString('#2436', $docSelfhost);
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
        $this->assertStringContainsString('PHP_COMPILER_LLVM_MEMORY_LIMIT="${PHP_COMPILER_LLVM_MEMORY_LIMIT:-6144M}"', $body);
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

    public function testDockerWrappersSourceCiDockerPreflight(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['docker-ci-local.sh', 'docker-exec.sh', 'ci-docker-safe.sh'] as $script) {
            $body = (string) file_get_contents($root.'/script/'.$script);
            $this->assertStringContainsString('ci-docker-preflight.sh', $body, $script);
            $this->assertStringContainsString('ci_docker_preflight', $body, $script);
            $this->assertStringContainsString('ci_docker_acquire_single_ci_lock', $body, $script);
        }
    }

    public function testCiDockerPreflightDefinesDockerInfoAndLock(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-preflight.sh');
        $this->assertStringContainsString('docker info', $body);
        $this->assertStringContainsString('flock -n 200', $body);
        $this->assertStringContainsString('flock -w', $body);
        $this->assertStringContainsString('PHP_COMPILER_CI_LOCK_WAIT_SEC', $body);
        $this->assertStringContainsString('.php-compiler-ci.lock', $body);
        $this->assertStringContainsString('PHP_COMPILER_CI_SINGLE_CONTAINER', $body);
    }

    public function testPhpunitShDoesNotReenterDockerExecWhenAlreadyInContainer(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/phpunit.sh');
        $this->assertStringContainsString('/.dockerenv', $body);
        $this->assertStringContainsString('PHP_COMPILER_IN_DOCKER', $body);
        $this->assertStringContainsString('docker-exec.sh', $body);
    }

    public function testApplyPatchesShHasGitExecutableBit(): void
    {
        $path = dirname(__DIR__, 2).'/script/apply-patches.sh';
        $this->assertTrue(is_executable($path), 'apply-patches.sh must be executable (git 100755)');
    }

    public function testBootstrapSelfhostLinkInvokesApplyPatchesViaBash(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/bootstrap-selfhost-link.sh');
        $this->assertStringContainsString('bash "${ROOT}/script/apply-patches.sh"', $body);
        $this->assertStringContainsString('chmod +x "${ROOT}/script/apply-patches.sh"', $body);
    }

    public function testSelfhostPreflightScriptDefinesModesAndDockerPath(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/selfhost-preflight.sh');
        $this->assertStringContainsString('selfhost_preflight', $body);
        $this->assertStringContainsString('php-or-docker', $body);
        $this->assertStringContainsString('docker-only', $body);
        $this->assertStringContainsString('environment prerequisites missing', $body);
        $this->assertStringContainsString('./script/docker-exec.sh', $body);
        $this->assertStringContainsString('bootstrap-selfhost-gate.sh', $body);
        $this->assertStringContainsString('do not nest', $body);
        $this->assertStringContainsString('selfhost_preflight_warn_nested_docker', $body);
        $this->assertStringContainsString('#2757', $body);
        $this->assertStringContainsString('issues/1492', $body);
    }

    public function testDockerExecWarnsOnNestedDockerInInnerCommand(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/docker-exec.sh');
        $this->assertStringContainsString('selfhost_preflight_warn_nested_docker', $body);
    }

    public function testBootstrapScriptsSourceSelfhostPreflight(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (
            [
                'bootstrap-selfhost-link.sh',
                'bootstrap-selfhost-helloworld-probe.sh',
                'bootstrap-loop-probe.sh',
                'docker-exec.sh',
            ] as $script
        ) {
            $body = (string) file_get_contents($root.'/script/'.$script);
            $this->assertStringContainsString('selfhost-preflight.sh', $body, $script);
            $this->assertStringContainsString('selfhost_preflight', $body, $script);
        }
    }

    public function testLocalCiMatrixDocumentsDockerPreflight(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ci-docker-preflight.sh', $doc);
        $this->assertStringContainsString('#2246', $doc);
        $this->assertStringContainsString('.php-compiler-ci.lock', $doc);
    }

    public function testCiDockerRunPassesHarnessDockerRunOpts(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('HARNESS_DOCKER_RUN_OPTS', $body);
        $this->assertStringContainsString('PHP_COMPILER_DOCKER_RUN_OPTS', $body);
        $this->assertStringContainsString('PHP_COMPILER_REQUIRE_DOCKER_RUN_OPTS', $body);
        $this->assertStringContainsString('ci_docker_harness_context', $body);
        $this->assertStringContainsString('_ci_docker_common_args', $body);
    }

    public function testDockerWrappersSourceCiDockerRun(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['docker-ci-local.sh', 'docker-exec.sh', 'ci-docker-safe.sh', 'docker-ci.sh'] as $script) {
            $body = (string) file_get_contents($root.'/script/'.$script);
            $this->assertStringContainsString('ci-docker-run.sh', $body, $script);
            $this->assertStringContainsString('ci_docker_run', $body, $script);
        }
        $cap = (string) file_get_contents($root.'/script/docker-capability-matrix.sh');
        $this->assertStringContainsString('ci-docker-run.sh', $cap);
        $this->assertStringContainsString('ci_docker_create', $cap);
    }

    public function testDockerExecRejectsNestedDockerInInnerCommand(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/docker-exec.sh');
        $this->assertStringContainsString('_docker_exec_reject_nested_docker', $body);
        $this->assertStringContainsString('docker info', $body);
        $this->assertStringContainsString('#2674', $body);
        $this->assertStringContainsString('#2757', $body);
        $this->assertStringContainsString('bootstrap-selfhost-gate', $body);
        $this->assertStringContainsString('environment misuse', $body);
    }

    /** Tar-fallback must not leak docker create containers (#2708). */
    public function testDockerExecTarFallbackContainerCleanup(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/docker-exec.sh');
        $this->assertStringContainsString('_tar_fallback_cleanup', $body);
        $this->assertStringContainsString('php-compiler.tar-fallback=1', $body);
        $this->assertStringContainsString('trap _tar_fallback_cleanup EXIT INT TERM', $body);
        // No sync-back path uses --rm via ci_docker_run (same as docker-ci-local.sh tar mode).
        $this->assertStringContainsString(
            "tar -cf - --exclude='.git' --exclude='.llvm' . | ci_docker_run -i -w /compiler",
            $body
        );
    }

    public function testLocalCiMatrixDocumentsHarnessDockerRunOpts(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('HARNESS_DOCKER_RUN_OPTS', $doc);
        $this->assertStringContainsString('PHP_COMPILER_REQUIRE_DOCKER_RUN_OPTS', $doc);
        $this->assertStringContainsString('#2249', $doc);
        $this->assertStringContainsString('make test-harness', $doc);
    }

    /**
     * Primary-path docs must not copy-paste raw bind-mount docker run (#2245).
     */
    public function testPrimaryDocsDoNotRecommendRawDockerBindMount(): void
    {
        $root = dirname(__DIR__, 2);
        $bad = 'docker run --rm -v "$(pwd):/compiler"';
        foreach (
            [
                'README.md',
                'docs/bootstrap-selfhost.md',
                'examples/README.md',
                'docs/deploy-web-aot.md',
                'docs/runtime-semantics.md',
            ] as $rel
        ) {
            $doc = (string) file_get_contents($root.'/'.$rel);
            $this->assertStringNotContainsString($bad, $doc, $rel);
        }
    }

    public function testMakefileTestHarnessRequiresDockerRunOpts(): void
    {
        $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/Makefile');
        $this->assertStringContainsString('PHP_COMPILER_REQUIRE_DOCKER_RUN_OPTS=1', $makefile);
        $this->assertStringContainsString('test-harness:', $makefile);
        $this->assertStringContainsString('test-docker-exec:', $makefile);
    }

    public function testCiDockerRunPassesJitPreflightGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('JIT_PREFLIGHT_GATE', $body);
    }

    public function testDockerExecTarFallbackSyncsM5BuildDrivers(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/docker-exec.sh');
        $this->assertStringContainsString('_docker_exec_m5_sync_back_paths', $body);
        $this->assertStringContainsString('build/bin-compile-aot-inventory', $body);
        $this->assertStringContainsString('build/selfhost-lib-spine-smoke', $body);
        $this->assertStringContainsString('build/selfhost"', $body);
        $this->assertStringContainsString('bootstrap-selfhost-lib-spine-smoke', $body);
        $this->assertStringContainsString('bootstrap-selfhost-link', $body);
        $this->assertStringContainsString('#2963', $body);
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
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT_GATE', $doc);
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT', $doc);
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
        $this->assertStringContainsString('BOOTSTRAP_WAVE_CHECK_VENDOR_ABSENT=0', $doc);
    }

    public function testInstallLlvm14ScriptMirrorsLlvm9Layout(): void
    {
        $script = dirname(__DIR__, 2).'/script/install-llvm14.sh';
        $this->assertFileExists($script);
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('libLLVM-14.so.1', $body);
        $this->assertStringContainsString('clang-14', $body);
        $this->assertStringContainsString('PHP_COMPILER_LLVM14_INSTALL_DIR', $body);
        $this->assertStringContainsString('.llvm14', $body);
        $this->assertStringContainsString('PHPLLVM FFI still targets LLVM 9', $body);

        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('install-llvm14.sh', $doc);
        $this->assertStringContainsString('LLVM 14 migration', $doc);
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

    public function testLocalCiMatrixDocumentsRebuildExamples009SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('REBUILD_EXAMPLES_009_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-rebuild-examples-009-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `REBUILD_EXAMPLES_009_SYNC_GATE` \| `1` \|/', $doc);
    }

    public function testLocalCiMatrixDocumentsRebuildExamples003JitProjectSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-rebuild-examples-003-jit-project-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `REBUILD_EXAMPLES_003_JIT_PROJECT_SYNC_GATE` \| `0` \|/', $doc);
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

    public function testLocalCiMatrixDocumentsCapabilitiesOopSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('CAPABILITIES_OOP_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-capabilities-oop-sync.php', $doc);
        $this->assertMatchesRegularExpression('/\| `CAPABILITIES_OOP_SYNC_GATE` \| `0` \|/', $doc);
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

    public function testCiDefaultsEnvDefinesRootReadme008SyncGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ROOT_README_008_SYNC_GATE="${ROOT_README_008_SYNC_GATE:-0}"', $defaults);
    }

    public function testCiFastRunsRootReadme008SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_root_readme_008_sync_check', $common);
        $this->assertStringContainsString('ROOT_README_008_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesRootReadme008SyncGateDefaultOff(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ROOT_README_008_SYNC_GATE=${ROOT_README_008_SYNC_GATE:-0}', $body);
    }

    public function testLocalCiMatrixDocumentsRootReadme008SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_008_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_008_SYNC_GATE` \| `0` \|/', $doc);
    }

    public function testCiDefaultsEnvDefinesGettingStartedSelfhostprobeSyncGateOff(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE="${GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE:-0}"',
            $defaults
        );
    }

    public function testCiFastRunsGettingStartedSelfhostprobeSyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_getting_started_selfhostprobe_sync_check', $common);
        $this->assertStringContainsString('check-getting-started-selfhostprobe-sync.php', $common);
        $this->assertStringContainsString('GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE:-0', $common);
    }

    public function testCiDockerRunPassesGettingStartedSelfhostprobeSyncGateDefaultOff(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE=${GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE:-0}',
            $body
        );
    }

    public function testLocalCiMatrixDocumentsGettingStartedSelfhostprobeSyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE', $doc);
        $this->assertStringContainsString('check-getting-started-selfhostprobe-sync.php', $doc);
        $this->assertMatchesRegularExpression(
            '/\| `GETTING_STARTED_SELFHOSTPROBE_SYNC_GATE` \| `0` \|/',
            $doc
        );
    }

    public function testCiDefaultsEnvDefinesRootReadme009SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('ROOT_README_009_SYNC_GATE="${ROOT_README_009_SYNC_GATE:-1}"', $defaults);
    }

    public function testCiFastRunsRootReadme009SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_root_readme_009_sync_check', $common);
        $this->assertStringContainsString('check-root-readme-009-sync.php', $common);
        $this->assertStringContainsString('ROOT_README_009_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesRootReadme009SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('ROOT_README_009_SYNC_GATE=${ROOT_README_009_SYNC_GATE:-1}', $body);
    }

    public function testLocalCiMatrixDocumentsRootReadme009SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('ROOT_README_009_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `ROOT_README_009_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2353', $doc);
    }

    public function testCiDefaultsEnvDefinesDevelopmentStatus009SyncGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_009_SYNC_GATE="${DEVELOPMENT_STATUS_009_SYNC_GATE:-1}"',
            $defaults
        );
    }

    public function testCiFastRunsDevelopmentStatus009SyncViaInventoryChecks(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_development_status_009_sync_check', $common);
        $this->assertStringContainsString('check-development-status-009-sync.php', $common);
        $this->assertStringContainsString('DEVELOPMENT_STATUS_009_SYNC_GATE:-1', $common);
    }

    public function testCiDockerRunPassesDevelopmentStatus009SyncGateDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString(
            'DEVELOPMENT_STATUS_009_SYNC_GATE=${DEVELOPMENT_STATUS_009_SYNC_GATE:-1}',
            $body
        );
    }

    public function testLocalCiMatrixDocumentsDevelopmentStatus009SyncGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('DEVELOPMENT_STATUS_009_SYNC_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `DEVELOPMENT_STATUS_009_SYNC_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#2353', $doc);
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
        $this->assertStringContainsString('THROWSWEB_SERVE_AOT_SMOKE_GATE', $doc);
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE', $doc);
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
        $this->assertStringContainsString('THROWSWEB_SERVE_AOT_SMOKE_GATE="${THROWSWEB_SERVE_AOT_SMOKE_GATE:-1}"', $defaults);
        $this->assertStringContainsString('#2390', $defaults);
        $this->assertStringContainsString('THROWSWEB_AOT_LINK_GATE="${THROWSWEB_AOT_LINK_GATE:-1}"', $defaults);
        $this->assertStringContainsString('THROWSWEB_AOT_SMOKE_GATE="${THROWSWEB_AOT_SMOKE_GATE:-1}"', $defaults);
    }

    public function testCiDefaultsEnvDefinesThrowsWebServeJitGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'THROWSWEB_SERVE_JIT_SMOKE_GATE="${THROWSWEB_SERVE_JIT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2435', $defaults);
    }

    public function testCiCommonThrowsWebServeAotGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('THROWSWEB_SERVE_AOT_SMOKE_GATE:-1', $common);
    }

    public function testLocalCiMatrixDocumentsThrowsWebServeAotGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertMatchesRegularExpression(
            '/\| `THROWSWEB_SERVE_AOT_SMOKE_GATE` \| `1` \|/',
            $doc
        );
        $this->assertStringContainsString('#2390', $doc);
    }

    public function testCiDefaultsEnvDefinesSessionsWebServeAotGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'SESSIONS_WEB_SERVE_AOT_SMOKE_GATE="${SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2371', $defaults);
    }

    public function testCiDefaultsEnvDefinesFileUploadWebServeAotGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE="${FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2371', $defaults);
    }

    public function testCiCommonSessionsWebServeAotGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('SESSIONS_WEB_SERVE_AOT_SMOKE_GATE:-1', $common);
    }

    public function testCiCommonFileUploadWebServeAotGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE:-1', $common);
    }

    public function testLocalCiMatrixDocumentsSessionsWebServeAotGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertMatchesRegularExpression(
            '/\| `SESSIONS_WEB_SERVE_AOT_SMOKE_GATE` \| `1` \|/',
            $doc
        );
        $this->assertStringContainsString('#2371', $doc);
    }

    public function testLocalCiMatrixDocumentsFileUploadWebServeAotGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertMatchesRegularExpression(
            '/\| `FILE_UPLOAD_WEB_SERVE_AOT_SMOKE_GATE` \| `1` \|/',
            $doc
        );
        $this->assertStringContainsString('#2371', $doc);
    }

    public function testCiCommonThrowsWebServeJitGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('THROWSWEB_SERVE_JIT_SMOKE_GATE:-1', $common);
    }

    public function testLocalCiMatrixDocumentsThrowsWebServeJitGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertMatchesRegularExpression(
            '/\| `THROWSWEB_SERVE_JIT_SMOKE_GATE` \| `1` \|/',
            $doc
        );
        $this->assertStringContainsString('#2435', $doc);
    }

    public function testCiFastRunsMiniWebAppVmCliGateByDefault(): void
    {
        $fast = dirname(__DIR__, 2).'/script/ci-fast.sh';
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('MINIWEBAPP_VM_CLI_GATE', $body);
        $this->assertStringContainsString("MiniWebApp.*VmCli", $body);
        $this->assertStringContainsString('PhpcLintProjectTest', $body);
    }

    public function testCiDefaultsEnvDefinesMiniWebAppJitProjectGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'MINIWEBAPP_JIT_PROJECT_GATE="${MINIWEBAPP_JIT_PROJECT_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#730', $defaults);
    }

    public function testCiFastRunsMiniWebAppJitProjectGateByDefault(): void
    {
        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_jit_project', $fast);
        $this->assertStringContainsString('#730', $fast);
    }

    public function testCiCommonMiniWebAppJitProjectGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_jit_project', $common);
        $this->assertStringContainsString('MINIWEBAPP_JIT_PROJECT_GATE:-1', $common);
        $this->assertStringContainsString('#730', $common);
    }

    public function testLocalCiMatrixDocumentsMiniWebAppJitProjectGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('MINIWEBAPP_JIT_PROJECT_GATE', $doc);
        $this->assertMatchesRegularExpression('/\| `MINIWEBAPP_JIT_PROJECT_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('#730', $doc);
    }

    public function testCiCommonDefinesMiniWebAppVmOopGateDefaultOn(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_vm_oop', $common);
        $this->assertStringContainsString('MINIWEBAPP_VM_OOP_GATE:-1', $common);
        $this->assertStringContainsString('check-miniwebapp-vm-oop.sh', $common);

        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'MINIWEBAPP_VM_OOP_GATE="${MINIWEBAPP_VM_OOP_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2293', $defaults);

        $fast = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-fast.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_vm_oop', $fast);

        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_miniwebapp_vm_oop', $local);

        $this->assertFileExists(dirname(__DIR__, 2).'/script/check-miniwebapp-vm-oop.sh');
    }

    public function testLocalCiMatrixDocumentsMiniWebAppVmOopGateDefaultOn(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('MINIWEBAPP_VM_OOP_GATE', $doc);
        $this->assertStringContainsString('check-miniwebapp-vm-oop.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `MINIWEBAPP_VM_OOP_GATE` \| `1` \|/', $doc);
        $this->assertStringContainsString('ci-local.sh', $doc);
        $this->assertStringContainsString('#2293', $doc);
    }

    public function testCiDockerRunPassesMiniWebAppVmOopGateEnvDefaultOn(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('MINIWEBAPP_VM_OOP_GATE=${MINIWEBAPP_VM_OOP_GATE:-1}', $body);
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
        $this->assertStringContainsString('--filter', $fast);
        $this->assertStringContainsString('array_rehash_string_keys', $fast);
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

    public function testCiDefaultsEnvDefinesJitServerSuperglobalGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString(
            'JIT_SERVER_SUPERGLOBAL_GATE="${JIT_SERVER_SUPERGLOBAL_GATE:-1}"',
            $defaults
        );
        $this->assertStringContainsString('#2292', $defaults);
    }

    public function testCiCommonDefinesJitServerSuperglobalRunner(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_jit_server_superglobal()', $common);
        $this->assertStringContainsString('JIT_SERVER_SUPERGLOBAL_GATE:-1', $common);
        $this->assertStringContainsString('--filter JitServerSuperglobal', $common);
    }

    public function testCiLocalRunsJitServerSuperglobalGateInLlvmTail(): void
    {
        $local = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-local.sh');
        $this->assertStringContainsString('ci_run_jit_server_superglobal', $local);
    }

    public function testLocalCiMatrixDocumentsJitServerSuperglobalGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('JIT_SERVER_SUPERGLOBAL_GATE', $doc);
        $this->assertStringContainsString('JitServerSuperglobal', $doc);
        $this->assertMatchesRegularExpression('/\| `JIT_SERVER_SUPERGLOBAL_GATE` \| `1` \|/', $doc);
    }

    public function testCiDockerRunPassesJitServerSuperglobalGateEnv(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-docker-run.sh');
        $this->assertStringContainsString('JIT_SERVER_SUPERGLOBAL_GATE', $body);
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

    public function testCheckInitSelfhostprobeParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-selfhostprobe-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/008-SelfHostProbe', $body);
        $this->assertStringContainsString('templates/init-selfhostprobe', $body);
    }

    public function testCheckInitSelfhostprobeParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-selfhostprobe-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitSelfhostprobeParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_selfhostprobe_parity_check', $common);
        $this->assertStringContainsString('check-init-selfhostprobe-parity.sh', $common);
        $this->assertStringContainsString('INIT_SELFHOSTPROBE_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesSelfhostprobeInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('INIT_SELFHOSTPROBE_PARITY_GATE="${INIT_SELFHOSTPROBE_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsSelfhostprobeInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('INIT_SELFHOSTPROBE_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-selfhostprobe-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `INIT_SELFHOSTPROBE_PARITY_GATE` \| `1` \|/', $doc);
    }

    public function testCheckInitFastcgiwebParityScriptExists(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-fastcgiweb-parity.sh';
        $this->assertFileExists($check);
        $this->assertTrue(is_executable($check));
        $body = (string) file_get_contents($check);
        $this->assertStringContainsString('examples/009-FastCGIWeb', $body);
        $this->assertStringContainsString('templates/init-fastcgiweb', $body);
    }

    public function testCheckInitFastcgiwebParityPassesInRepo(): void
    {
        $check = dirname(__DIR__, 2).'/script/check-init-fastcgiweb-parity.sh';
        exec('bash '.escapeshellarg($check).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testCiInventoryRunsInitFastcgiwebParityCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_init_fastcgiweb_parity_check', $common);
        $this->assertStringContainsString('check-init-fastcgiweb-parity.sh', $common);
        $this->assertStringContainsString('INIT_FASTCGIWEB_PARITY_GATE:-1', $common);
    }

    public function testCiDefaultsEnvDefinesFastcgiwebInitParityGateOn(): void
    {
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('INIT_FASTCGIWEB_PARITY_GATE="${INIT_FASTCGIWEB_PARITY_GATE:-1}"', $defaults);
    }

    public function testLocalCiMatrixDocumentsFastcgiwebInitParityGate(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2).'/docs/local-ci-matrix.md');
        $this->assertStringContainsString('INIT_FASTCGIWEB_PARITY_GATE', $doc);
        $this->assertStringContainsString('check-init-fastcgiweb-parity.sh', $doc);
        $this->assertMatchesRegularExpression('/\| `INIT_FASTCGIWEB_PARITY_GATE` \| `1` \|/', $doc);
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
