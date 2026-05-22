<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM CLI gate for 003-MiniWebApp PATH_INFO routing (no TCP, issue #595).
 *
 * @see https://github.com/PurHur/php-compiler/issues/595
 */
final class MiniWebAppPathInfoVmCliTest extends TestCase
{
    private string $publicDir;

    private string $vmBin;

    protected function setUp(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $index = $repoRoot.'/examples/003-MiniWebApp/public/index.php';
        if (!is_file($index)) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing (#246)');
        }
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }
        $this->publicDir = dirname($index);
        $this->vmBin = $vm;
    }

    public function testPathInfoHelloWithQueryString(): void
    {
        $out = $this->runIndex([
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/hello',
            'QUERY_STRING' => 'name=Dev',
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertStringContainsString('Hello Dev', $out);
    }

    public function testPathInfoContactForm(): void
    {
        $out = $this->runIndex([
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/contact',
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertStringContainsString('<h1>Contact</h1>', $out);
    }

    public function testPathInfoApiStatus(): void
    {
        $out = $this->runIndex([
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '/api/status',
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertStringContainsString('"ok":true', $out);
        $this->assertStringContainsString('003-MiniWebApp', $out);
    }

    public function testPathInfoEmptyDefaultsToHome(): void
    {
        $out = $this->runIndex([
            'SCRIPT_NAME' => '/index.php',
            'PATH_INFO' => '',
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertStringContainsString('MiniWebApp', $out);
        $this->assertStringContainsString('PATH_INFO', $out);
    }

    public function testPathInfoAbsentDefaultsToHome(): void
    {
        $out = $this->runIndex([
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_METHOD' => 'GET',
        ]);
        $this->assertStringContainsString('MiniWebApp', $out);
    }

    /**
     * @param array<string, string> $cgiEnv
     */
    private function runIndex(array $cgiEnv): string
    {
        $env = $this->baseEnv();
        foreach ($cgiEnv as $key => $value) {
            $env[$key] = $value;
        }

        $cmd = array_merge(self::phpCommand(), [$this->vmBin, 'index.php']);
        $result = $this->runCommand($cmd, $this->publicDir, $env);
        $this->assertSame(
            0,
            $result['code'],
            trim($result['stderr']."\n".$result['stdout'])
        );

        return $result['stdout'];
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

        return [
            'code' => $code,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => $stderr !== false ? $stderr : '',
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpCommand(): array
    {
        $phpEnv = getenv('PHP_COMPILER_PHP');
        if (false !== $phpEnv && '' !== $phpEnv) {
            return preg_split('/\s+/', $phpEnv) ?: [PHP_BINARY];
        }
        $cmd = [PHP_BINARY];
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
