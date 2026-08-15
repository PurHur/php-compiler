<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Host {@code zend.assertions} (php.ini or {@code -d}) must gate assert() like Zend (#31195 / #29551).
 */
final class ZendAssertionsHostDashDTest extends TestCase
{
    public function testHostDashDMinusOneNoThrow(): void
    {
        $this->assertSame("ini=-1\nAFTER\ndone\n", $this->runVmWithHostDashD('-1'));
    }

    public function testHostDashDZeroNoThrow(): void
    {
        $this->assertSame("ini=0\nAFTER\ndone\n", $this->runVmWithHostDashD('0'));
    }

    public function testHostDashDOneThrows(): void
    {
        $this->assertSame("ini=1\nAssertionError:nope\ndone\n", $this->runVmWithHostDashD('1'));
    }

    public function testHostDefaultMatchesProcessIni(): void
    {
        // Docker/production php.ini is typically -1; php -n is 1 (#31195).
        $host = (string) ini_get('zend.assertions');
        if ('-1' === $host || '0' === $host) {
            $this->assertSame("ini={$host}\nAFTER\ndone\n", $this->runVmWithHostDashD(null));
        } else {
            $this->assertSame("ini=1\nAssertionError:nope\ndone\n", $this->runVmWithHostDashD(null));
        }
    }

    public function testGuestDashDOverrideBeatsHost(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_29551_zend_assertions_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        // Host disables; guest enables → still throws.
        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.assertions=-1',
            '-d',
            'display_errors=0',
            $vm,
            '-d',
            'zend.assertions=1',
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame("ini=1\nAssertionError:nope\ndone\n", $result['stdout']);
    }

    public function testHostDashDMinusOneOnJitNoThrow(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $jit = realpath($repoRoot.'/bin/jit.php');
        $script = realpath($repoRoot.'/test/repro/issue_29551_zend_assertions_host_dash_d.php');
        if (false === $jit || false === $script) {
            $this->markTestSkipped('jit or repro missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.assertions=-1',
            '-d',
            'display_errors=0',
            $jit,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame("ini=-1\nAFTER\ndone\n", $result['stdout']);
    }

    public function testHostDefaultOnJitMatchesProcessIni(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $jit = realpath($repoRoot.'/bin/jit.php');
        $script = realpath($repoRoot.'/test/repro/issue_29551_zend_assertions_host_dash_d.php');
        if (false === $jit || false === $script) {
            $this->markTestSkipped('jit or repro missing');
        }

        $host = (string) ini_get('zend.assertions');
        $cmd = [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            $jit,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        if ('-1' === $host || '0' === $host) {
            $this->assertSame("ini={$host}\nAFTER\ndone\n", $result['stdout']);
        } else {
            $this->assertSame("ini=1\nAssertionError:nope\ndone\n", $result['stdout']);
        }
    }

    private function runVmWithHostDashD(?string $mode): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_29551_zend_assertions_host_dash_d.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        $cmd = [PHP_BINARY, '-d', 'display_errors=0'];
        if (null !== $mode) {
            $cmd[] = '-d';
            $cmd[] = 'zend.assertions='.$mode;
        }
        $cmd[] = $vm;
        $cmd[] = $script;
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);

        return $result['stdout'];
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
