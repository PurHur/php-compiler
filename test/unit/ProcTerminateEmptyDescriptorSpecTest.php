<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #25195: proc_terminate() must kill empty-descriptor_spec children so the
 * harness stdout pipe reaches EOF (SIGSTOP race left orphans in state T).
 */
final class ProcTerminateEmptyDescriptorSpecTest extends TestCase
{
    public function testTerminateKillsEmptyDescriptorSpecChild(): void
    {
        $script = <<<'PHP'
<?php
// sleep keeps the child alive after resume so terminate is observable (#25195).
$result = proc_open('sleep 30', [], $pipes);
if (!is_resource($result)) {
    fwrite(STDERR, "open failed\n");
    exit(2);
}
$ok = proc_terminate($result);
usleep(200000);
$st2 = proc_get_status($result);
@proc_close($result);
echo 'terminate=', (int) $ok, ' running=', (int) ($st2['running'] ?? -1),
    ' signaled=', (int) ($st2['signaled'] ?? -1), ' termsig=', (int) ($st2['termsig'] ?? -1), "\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'proc_term_');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '.escapeshellarg($tmp);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        self::assertIsResource($proc);
        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc, 9);
            proc_close($proc);
            @unlink($tmp);
            self::fail('vm.php hung after proc_terminate on empty descriptor_spec (#25195)');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($tmp);

        self::assertSame(
            "terminate=1 running=0 signaled=1 termsig=15\n",
            $stdout,
            'stderr='.$stderr
        );
    }
}
