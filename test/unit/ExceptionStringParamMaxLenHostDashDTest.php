<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Host {@code php -d zend.exception_string_param_max_len=0 bin/vm.php} must redact
 * UnhandledMatchError string subjects (#24487).
 */
final class ExceptionStringParamMaxLenHostDashDTest extends TestCase
{
    public function testHostDashDZeroRedactsUnhandledMatchString(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_24487_unhandled_match_host_max_len.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_string_param_max_len=0',
            '-d',
            'display_errors=0',
            $vm,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame("Unhandled match case '...'\n0\n", $result['stdout']);
    }

    public function testHostDashDFiveTruncatesUnhandledMatchString(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_24487_unhandled_match_host_max_len.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_string_param_max_len=5',
            '-d',
            'display_errors=0',
            $vm,
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame("Unhandled match case 'secre...'\n5\n", $result['stdout']);
    }

    public function testGuestDashDOverrideBeatsHost(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        $script = realpath($repoRoot.'/test/repro/issue_24487_unhandled_match_host_max_len.php');
        if (false === $vm || false === $script) {
            $this->markTestSkipped('vm or repro missing');
        }

        // Host 0, guest 15 → subject fits without truncation (php-src compiled default).
        $cmd = [
            PHP_BINARY,
            '-d',
            'zend.exception_string_param_max_len=0',
            '-d',
            'display_errors=0',
            $vm,
            '-d',
            'zend.exception_string_param_max_len=15',
            $script,
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertSame("Unhandled match case 'secret-subject'\n15\n", $result['stdout']);
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
