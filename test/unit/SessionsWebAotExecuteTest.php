<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * AOT CLI execute gate for examples/005-SessionsWeb session flash (#1891).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 * @group sessionsweb
 * @group sessionsweb-aot-execute
 */
final class SessionsWebAotExecuteTest extends TestCase
{
    private string $repoRoot;

    private string $binary;

    private string $sessionDir;

    protected function setUp(): void
    {
        $gate = getenv('SESSIONS_WEB_AOT_SMOKE_GATE');
        if (false !== $gate && '' !== $gate && '1' !== $gate) {
            $this->markTestSkipped(
                'SESSIONS_WEB_AOT_SMOKE_GATE=0 — set to 1 to run SessionsWeb AOT execute tests (#1891)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/'.SessionsWebCgiEnv::PROJECT_REL;
        if (!is_file($project.'/example.php')) {
            $this->markTestSkipped('examples/005-SessionsWeb missing (#1881)');
        }
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $phpc = $this->repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $this->sessionDir = sys_get_temp_dir().'/phpc_sessionsweb_aot_'.uniqid('', true);
        $this->assertTrue(@mkdir($this->sessionDir, 0700, true));

        $env = $this->baseEnv();
        $env['PHP_COMPILER_SESSION_DIR'] = $this->sessionDir;
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        if (0 !== $exit && PhpcBuild::isUserClassAotBlocked($stderr)) {
            $this->markTestSkipped(
                '005-SessionsWeb native AOT execute blocked: '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        $this->assertFileExists($this->binary);
    }

    protected function tearDown(): void
    {
        if (isset($this->sessionDir) && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir.'/sess_*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sessionDir);
        }
        putenv('PHP_COMPILER_SESSION_DIR');
        putenv('HTTP_COOKIE');
        parent::tearDown();
    }

    public function testPostRedirectFlashRoundTrip(): void
    {
        $jar = new CgiCookieJar();
        $empty = $this->runBinary(SessionsWebCgiEnv::getEmpty());
        $this->assertStringContainsString('No flash message yet', $empty);
        $jar->absorbFromCgiOutput($empty);
        $this->assertTrue($jar->hasCookie('PHPSESSID'), 'session_start should emit PHPSESSID cookie');

        $cookie = $jar->httpCookieHeader();
        $this->runBinary(array_merge(
            SessionsWebCgiEnv::postFlash('Saved'),
            ['HTTP_COOKIE' => $cookie]
        ));

        $flash = $this->runBinary(array_merge(
            SessionsWebCgiEnv::getEmpty(),
            ['HTTP_COOKIE' => $jar->httpCookieHeader()]
        ));
        $this->assertStringContainsString('Flash: Saved', $flash);

        $after = $this->runBinary(array_merge(
            SessionsWebCgiEnv::getEmpty(),
            ['HTTP_COOKIE' => $jar->httpCookieHeader()]
        ));
        $this->assertStringContainsString('No flash message yet', $after);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runBinary(array $cgiEnv): string
    {
        $env = $this->baseEnv();
        $env['PHP_COMPILER_SESSION_DIR'] = $this->sessionDir;
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }

        return $this->runCommand([$this->binary], $this->repoRoot, $env)['stdout'];
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && !isset($env[$key])) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param list<string>              $cmd
     * @param array<string, string>     $env
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd, array $env): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(
            0,
            $code,
            trim(($stderr !== false ? $stderr : '')."\n".($stdout !== false ? $stdout : ''))
        );

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }
}
