<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: bare CLI session_start must not printf Set-Cookie (#33445).
 *
 * @group llvm
 * @group aot
 */
final class SessionCliNoSetcookie33445AotTest extends TestCase
{
    public function testCliAotMatchesVmBodyOnly(): void
    {
        $repo = dirname(__DIR__, 2);
        $repro = $repo.'/test/repro/session_cli_no_setcookie_aot.php';
        $this->assertFileExists($repro);

        $vm = $this->runPhp([$repo.'/bin/vm.php', $repro], $repo, []);
        $this->assertSame(0, $vm['code'], $vm['stderr']);
        $this->assertSame('1', $vm['stdout']);
        $this->assertStringNotContainsString('Set-Cookie', $vm['stdout']);

        $bin = sys_get_temp_dir().'/phpc_session_cli_33445_'.uniqid('', true).'.bin';
        $compile = $this->runPhp([$repo.'/bin/compile.php', '-o', $bin, $repro], $repo, []);
        $this->assertSame(0, $compile['code'], $compile['stderr']."\n".$compile['stdout']);
        $this->assertFileExists($bin);

        try {
            $cli = $this->runCommand([$bin], $repo, $this->baseEnvWithoutRequestMethod());
            $this->assertSame(0, $cli['code'], $cli['stderr']."\n".$cli['stdout']);
            $this->assertSame('1', $cli['stdout'], 'CLI AOT must match VM/Zend body only');
            $this->assertStringNotContainsString('Set-Cookie', $cli['stdout']);

            $cgiEnv = $this->baseEnvWithoutRequestMethod();
            $cgiEnv['REQUEST_METHOD'] = 'GET';
            $cgi = $this->runCommand([$bin], $repo, $cgiEnv);
            $this->assertSame(0, $cgi['code'], $cgi['stderr']."\n".$cgi['stdout']);
            $this->assertStringContainsString('1', $cgi['stdout']);
            $this->assertStringContainsString('Set-Cookie: PHPSESSID=', $cgi['stdout']);
            $this->assertMatchesRegularExpression('/Set-Cookie: PHPSESSID=[^;\\s]+; path=\\/\\r?\\n/', $cgi['stdout']);
        } finally {
            @unlink($bin);
        }
    }

    /**
     * @return array<string, string>
     */
    private function baseEnvWithoutRequestMethod(): array
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
        unset($env['REQUEST_METHOD'], $env['GATEWAY_INTERFACE']);

        return $env;
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runPhp(array $cmd, string $cwd, array $env): array
    {
        $fullEnv = $this->baseEnvWithoutRequestMethod();
        foreach ($env as $k => $v) {
            $fullEnv[$k] = $v;
        }

        return $this->runCommand(array_merge([PHP_BINARY, '-d', 'memory_limit=512M'], $cmd), $cwd, $fullEnv);
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
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
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }
}
