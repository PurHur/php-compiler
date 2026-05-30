<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bin/vm.php / bin/jit.php resolve script paths against pre-chdir cwd (#1770, #586).
 */
final class CliInvocationCwdTest extends TestCase
{
    public function testVmResolvesScriptRelativeToInvocationCwd(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $publicDir = $repoRoot.'/examples/003-MiniWebApp/public';
        $index = $publicDir.'/index.php';
        if (!is_file($index)) {
            $this->markTestSkipped('examples/003-MiniWebApp/public/index.php missing');
        }
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $env = $this->baseEnv();
        foreach (MiniWebAppCgiEnv::queryRouteHome() as $key => $value) {
            $env[$key] = $value;
        }

        $cmd = array_merge(self::phpCommand(), [$vm, 'index.php']);
        $result = $this->runCommand($cmd, $publicDir, $env);
        $this->assertSame(0, $result['code'], trim($result['stderr']."\n".$result['stdout']));
        $this->assertStringNotContainsString('Could not open file', $result['stdout']);
        $this->assertStringContainsString(MiniWebAppCgiEnv::APP_NAME, $result['stdout']);
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

        return [PHP_BINARY];
    }
}
