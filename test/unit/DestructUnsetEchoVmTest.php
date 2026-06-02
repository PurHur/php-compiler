<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM: unset($o) runs __destruct before later output (#4096). */
final class DestructUnsetEchoVmTest extends TestCase
{
    public function testUnsetRunsDestructorBeforeFollowingEcho(): void
    {
        $path = dirname(__DIR__).'/repro-maintainer/destruct_unset_echo_4096.php';
        $this->assertSame("dtor\nafter\n", $this->runBin('bin/vm.php', $path));
    }

    public function testAotUnsetRunsDestructorBeforeFollowingEcho(): void
    {
        $path = dirname(__DIR__).'/repro-maintainer/destruct_unset_echo_4096.php';
        $repo = dirname(__DIR__, 2);
        $out = $repo.'/build/test-destruct-unset-echo-4096';
        $proc = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), trim((string) $err));
        $this->assertSame("dtor\nafter\n", $this->runBin($out, $path));
    }

    private function runBin(string $bin, string $scriptPath = ''): string
    {
        $repo = dirname(__DIR__, 2);
        $cmd = str_contains($bin, '/') && !str_starts_with($bin, 'bin/')
            ? [$bin]
            : ['php', $repo.'/'.$bin, $scriptPath];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $err));

        return (string) $out;
    }
}
