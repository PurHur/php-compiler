<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT session file persistence across two process invocations (#1938).
 *
 * @group llvm
 * @group aot
 */
final class SessionAotPersistTest extends TestCase
{
    private ?string $sessionDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->sessionDir && is_dir($this->sessionDir)) {
            foreach (glob($this->sessionDir.'/sess_*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->sessionDir);
        }
        putenv('PHP_COMPILER_SESSION_DIR');
        putenv('HTTP_COOKIE');
        parent::tearDown();
    }

    public function testSessionPersistsAcrossAotRuns(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $this->sessionDir = sys_get_temp_dir().'/phpc_aot_session_'.uniqid('', true);
        @mkdir($this->sessionDir, 0700, true);

        $writeCode = <<<'PHP'
<?php
session_start();
$_SESSION['user'] = 'dev';
$id = session_id();
session_write_close();
echo $id;
PHP;

        $sessionId = $this->extractSessionId($this->runAot($writeCode));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sessionId);

        putenv('HTTP_COOKIE=PHPSESSID='.$sessionId);

        $readCode = <<<'PHP'
<?php
session_start();
echo $_SESSION['user'] ?? 'missing';
PHP;

        $this->assertSame('dev', trim($this->runAot($readCode)));
    }

    private function extractSessionId(string $output): string
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; --$i) {
            $line = trim($lines[$i]);
            if ('' !== $line && preg_match('/^[a-f0-9]{32}$/', $line)) {
                return $line;
            }
        }

        return trim($output);
    }

    private function runAot(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $outfile = tempnam(sys_get_temp_dir(), 'phpc_aot_sess_');
        $this->assertNotFalse($outfile);
        @unlink($outfile);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        putenv('PHP_COMPILER_SESSION_DIR='.$this->sessionDir);
        $env['PHP_COMPILER_SESSION_DIR'] = $this->sessionDir;
        $cookie = getenv('HTTP_COOKIE');
        if (is_string($cookie) && '' !== $cookie) {
            $env['HTTP_COOKIE'] = $cookie;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $outfile],
            $descriptorSpec,
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $compileExit = proc_close($compile);
        $this->assertSame(0, $compileExit, trim($compileErr !== false ? $compileErr : ''));
        $this->assertFileExists($outfile);

        $run = proc_open(
            [$outfile],
            $descriptorSpec,
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $stderr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runExit = proc_close($run);
        @unlink($outfile);
        $this->assertSame(0, $runExit, trim(($stderr !== false ? $stderr : '').($stdout !== false ? $stdout : '')));

        return $stdout !== false ? $stdout : '';
    }
}
