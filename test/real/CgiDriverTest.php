<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM smoke for bin/cgi.php with CGI env only (no TCP, issues #50, #656).
 *
 * @group cgi
 */
final class CgiDriverTest extends TestCase
{
    private string $repoRoot;

    /** @var list<string> */
    private array $phpCmd = [];

    private string $cgiBin;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $cgi = realpath($this->repoRoot.'/bin/cgi.php');
        if (false === $cgi) {
            $this->markTestSkipped('bin/cgi.php missing (#50)');
        }
        $this->cgiBin = $cgi;
        $this->phpCmd = self::phpCommand();
    }

    public function testSimpleWebGetViaCgiDriver(): void
    {
        $script = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($script);

        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'GET';
        $env['QUERY_STRING'] = 'name=CgiTest';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['REQUEST_URI'] = '/example.php?name=CgiTest';

        $out = $this->runCgi($script, $env);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('Content-Type: text/html', $out);
        $this->assertStringContainsString('CgiTest', $this->cgiBody($out));
    }

    public function testSimpleWebPostViaCgiDriver(): void
    {
        $script = $this->repoRoot.'/examples/001-SimpleWeb/example.php';
        $this->assertFileExists($script);

        $body = 'name=PostCgi';
        $env = $this->baseEnv();
        $env['REQUEST_METHOD'] = 'POST';
        $env['QUERY_STRING'] = '';
        $env['CONTENT_LENGTH'] = (string) strlen($body);
        $env['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $env['SCRIPT_NAME'] = '/example.php';
        $env['SCRIPT_FILENAME'] = $script;
        $env['REQUEST_URI'] = '/example.php';

        $out = $this->runCgi($script, $env, $body);
        $this->assertStringContainsString('Status: 200', $out);
        $this->assertStringContainsString('PostCgi', $this->cgiBody($out));
    }

    /**
     * @param array<string, string> $env
     */
    private function runCgi(string $script, array $env, string $stdin = ''): string
    {
        $cmd = array_merge($this->phpCmd, [$this->cgiBin, $script]);
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, null, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, trim((false !== $stderr ? $stderr : '')."\n".(false !== $stdout ? $stdout : '')));

        return false !== $stdout ? $stdout : '';
    }

    private function cgiBody(string $output): string
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $output, 2);

        return $parts[1] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            $cmd = preg_split('/\s+/', $phpEnv);
        } else {
            $cmd = [PHP_BINARY];
        }
        $extDir = getenv('PHP_COMPILER_EXT_DIR') ?: '/usr/lib/php/20220829';
        if (is_dir($extDir)) {
            foreach (['tokenizer', 'mbstring', 'dom', 'xml', 'xmlwriter', 'ffi', 'posix', 'phar'] as $ext) {
                $so = $extDir.'/'.$ext.'.so';
                if (is_file($so)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension='.$so;
                }
            }
        }

        return $cmd;
    }
}
