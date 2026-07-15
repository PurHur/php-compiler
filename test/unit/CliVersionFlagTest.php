<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** bin/* -v/--version and invalid option exit codes (issue #18691, sapi/cli/php_cli.c). */
final class CliVersionFlagTest extends TestCase
{
    public function testVmVersionShortFlagPrintsBannerAndExitsZero(): void
    {
        $result = $this->runVm(['-v']);
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('PHP Compiler', $result['stdout']);
        $this->assertStringContainsString('host PHP '.PHP_VERSION, $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testVmVersionLongFlagPrintsBannerAndExitsZero(): void
    {
        $result = $this->runVm(['--version']);
        $this->assertSame(0, $result['code']);
        $this->assertStringContainsString('PHP Compiler', $result['stdout']);
        $this->assertStringContainsString(CompilerVersion::reportedPhpVersion(), $result['stdout']);
    }

    public function testVmUnknownOptionExitsNonzero(): void
    {
        $result = $this->runVm(['--not-a-real-flag']);
        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('Unsupported bare argument --not-a-real-flag', $result['stderr']);
        $this->assertSame('', $result['stdout']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runVm(array $args): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $cmd = array_merge(self::phpCommand(), [$vm], $args);

        return $this->runCommand($cmd, $repoRoot);
    }

    /**
     * @param list<string> $cmd
     *
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runCommand(array $cmd, string $cwd): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
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
