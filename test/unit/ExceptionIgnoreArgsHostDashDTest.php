<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Host {@code php -d zend.exception_ignore_args=... bin/vm.php} must override guest compiled
 * default Off; bare host (php.ini alone) must not (#23408 / #28061).
 */
final class ExceptionIgnoreArgsHostDashDTest extends TestCase
{
    public function testHostDashDZeroIncludesSensitiveWrappedArgs(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_23408_exception_ignore_args_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_ignore_args=0',
            '-d',
            'display_errors=0',
            $vm,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame(
            "HAS_ARGS\nSensitiveParameterValue\nNO_LEAK\nWRAPPED\n",
            $result['stdout']
        );
    }

    public function testHostDefaultIncludesArgsMatchingPhpSrcCompiledDefault(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_23408_exception_ignore_args_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        // Distro php.ini may set On; guest must keep php-src compiled default Off (#28061).
        $cmd = [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            $vm,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame(
            "HAS_ARGS\nSensitiveParameterValue\nNO_LEAK\nWRAPPED\n",
            $result['stdout']
        );
    }

    public function testGuestDashDOverrideBeatsHost(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_23408_exception_ignore_args_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        // Host Off, guest On → args remain omitted (#21998).
        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_ignore_args=0',
            '-d',
            'display_errors=0',
            $vm,
            '-d',
            'zend.exception_ignore_args=1',
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertStringStartsWith("NO_ARGS\n", $result['stdout']);
    }

    public function testHostDashDOneOmitsArgs(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_23408_exception_ignore_args_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_ignore_args=1',
            '-d',
            'display_errors=0',
            $vm,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertStringStartsWith("NO_ARGS\n", $result['stdout']);
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
            'stdout' => false === $stdout ? '' : $stdout,
            'stderr' => false === $stderr ? '' : $stderr,
        ];
    }
}
