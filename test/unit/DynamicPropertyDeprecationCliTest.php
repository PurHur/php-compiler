<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** CLI -d error_reporting and dynamic property E_DEPRECATED (#11558). */
final class DynamicPropertyDeprecationCliTest extends TestCase
{
    public function testVmDashDErrorReportingEmitsDynamicPropertyDeprecation(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }

        $cmd = [
            PHP_BINARY,
            '-d',
            'error_reporting=0',
            '-d',
            'display_errors=0',
            $vm,
            '-d',
            'error_reporting=E_ALL',
            '-r',
            'class C{}; $c=new C; $c->x=1;',
        ];
        $result = $this->runCommand($cmd, $repoRoot);
        $this->assertSame(0, $result['code'], $result['stderr']."\n".$result['stdout']);
        $this->assertStringContainsString(
            'Creation of dynamic property C::$x is deprecated',
            $result['stderr']
        );
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
}
