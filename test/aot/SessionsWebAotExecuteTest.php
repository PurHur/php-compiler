<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * AOT two-request session flash for examples/005-SessionsWeb (issue #1891).
 *
 * @group llvm
 * @group aot
 * @group aot-link
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
                'SESSIONS_WEB_AOT_SMOKE_GATE=0 — set to 1 to run 005-SessionsWeb AOT execute tests (#1891)'
            );
        }
        $this->repoRoot = dirname(__DIR__, 2);
        $project = $this->repoRoot.'/examples/005-SessionsWeb';
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

        $this->sessionDir = sys_get_temp_dir().'/phpc_sess_aot_'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->sessionDir));

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
                '005-SessionsWeb native AOT link blocked: '.trim($stderr)
            );
        }
        $this->assertSame(0, $exit, 'phpc build --project failed: '.substr($stderr, 0, 500));

        $this->binary = $project.'/.phpc/bin/app';
        if (!is_executable($this->binary)) {
            $this->markTestSkipped('005-SessionsWeb AOT binary missing after link (#1891)');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->sessionDir) && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir.'/sess_*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sessionDir);
        }
        parent::tearDown();
    }

    public function testAotSessionFlashRoundTrip(): void
    {
        $jar = new CgiCookieJar();

        $empty = $this->runBinary(SessionsWebCgiEnv::getEmpty());
        $this->assertStringContainsString('No flash message yet', $empty);
        $jar->ingestCgiOutput($empty);
        $this->assertNotNull($jar->get('PHPSESSID'));

        $post = $this->runBinary(SessionsWebCgiEnv::postFlash('Saved'), $jar->httpCookieHeader());
        $this->assertMatchesRegularExpression('/\b303\b/', $post);

        $flash = $this->runBinary(SessionsWebCgiEnv::getEmpty(), $jar->httpCookieHeader());
        $this->assertStringContainsString('Flash: Saved', $flash);

        $after = $this->runBinary(SessionsWebCgiEnv::getEmpty(), $jar->httpCookieHeader());
        $this->assertStringContainsString('No flash message yet', $after);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runBinary(array $cgiEnv, string $httpCookie = ''): string
    {
        $env = $this->baseEnv();
        $env['PHP_COMPILER_SESSION_DIR'] = $this->sessionDir;
        LlvmToolchain::applyProcessEnv($env, $this->repoRoot);
        foreach (SessionsWebCgiEnv::aotFrontController($this->repoRoot) as $key => $value) {
            $env[$key] = $value;
        }
        $bodyFile = null;
        foreach ($cgiEnv as $key => $value) {
            if ('REQUEST_BODY' === $key && '' !== $value) {
                $bodyFile = tempnam(sys_get_temp_dir(), 'phpc_sess_post_');
                $this->assertNotFalse($bodyFile);
                file_put_contents($bodyFile, $value);
                $env['REQUEST_BODY_FILE'] = $bodyFile;
                continue;
            }
            $env[$key] = $value;
        }
        if ('' !== $httpCookie) {
            $env['HTTP_COOKIE'] = $httpCookie;
        }

        try {
            return $this->runCommand([$this->binary], $this->repoRoot, $env)['stdout'];
        } finally {
            if (null !== $bodyFile && is_file($bodyFile)) {
                @unlink($bodyFile);
            }
        }
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
