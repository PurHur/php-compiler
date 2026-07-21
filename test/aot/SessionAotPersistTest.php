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
        putenv('GATEWAY_INTERFACE');
        putenv('REQUEST_METHOD');
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

    public function testSessionPersistsAcrossWriteCloseWithinAotRun(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $this->sessionDir = sys_get_temp_dir().'/phpc_aot_session_'.uniqid('', true);
        @mkdir($this->sessionDir, 0700, true);

        $code = <<<'PHP'
<?php
session_start();
$_SESSION['k'] = 'v';
session_write_close();
session_start();
echo $_SESSION['k'] ?? 'missing';
PHP;

        $this->assertSame('v', trim($this->runAot($code)));
    }

    /** Thin AOT: int/bool session HT values cast and echo without segfault (#21948). */
    public function testSessionIntBoolCastAndEchoUnderAot(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $this->sessionDir = sys_get_temp_dir().'/phpc_aot_session_ib_'.uniqid('', true);
        @mkdir($this->sessionDir, 0700, true);
        // Thin AOT session scalar HT matches CGI/file-backed path (#21948 issue repro).
        putenv('GATEWAY_INTERFACE=CGI/1.1');
        putenv('REQUEST_METHOD=GET');

        $direct = <<<'PHP'
<?php
session_start();
$_SESSION['n'] = 3;
$_SESSION['t'] = true;
echo 'cast=', (string)$_SESSION['n'], "\n";
echo 'bool=', (string)$_SESSION['t'], "\n";
echo 'echo=';
echo $_SESSION['n'];
echo "\n";
echo 'truth=', $_SESSION['t'] ? '1' : '0', "\n";
PHP;
        $directOut = $this->stripCgiTrailer($this->runAot($direct));
        $this->assertSame("cast=3\nbool=1\necho=3\ntruth=1\n", $directOut);

        $sid = 'abcdefghij0123456789KL-nop';
        file_put_contents($this->sessionDir.'/sess_'.$sid, 'n|i:3;t|b:1;');
        putenv('HTTP_COOKIE=PHPSESSID='.$sid);

        $loaded = <<<'PHP'
<?php
session_start();
echo 'isset=', isset($_SESSION['n']) ? '1' : '0', "\n";
echo 'cast=', (string)$_SESSION['n'], "\n";
echo 'truth=', $_SESSION['t'] ? '1' : '0', "\n";
echo 'bstr=', (string)$_SESSION['t'], "\n";
PHP;
        $loadedOut = $this->stripCgiTrailer($this->runAot($loaded));
        $this->assertSame("isset=1\ncast=3\ntruth=1\nbstr=1\n", $loadedOut);
    }

    /** Drop CGI Set-Cookie trailer noise from session shutdown (#21948 asserts). */
    private function stripCgiTrailer(string $output): string
    {
        $cut = strpos($output, 'Set-Cookie:');
        if (false !== $cut) {
            $output = substr($output, 0, $cut);
        }

        return $output;
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
        foreach (['GATEWAY_INTERFACE', 'REQUEST_METHOD'] as $cgiKey) {
            $cgiVal = getenv($cgiKey);
            if (is_string($cgiVal) && '' !== $cgiVal) {
                $env[$cgiKey] = $cgiVal;
            }
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
