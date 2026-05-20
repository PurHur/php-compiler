<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ExitTest extends TestCase
{
    public function testExitZeroStopsExecution(): void
    {
        $result = $this->runVm('-r', 'echo "a"; exit(0); echo "b";');
        $this->assertSame(0, $result['exit']);
        $this->assertSame("a", $result['stdout']);
    }

    public function testExitIntegerStatusPropagates(): void
    {
        $result = $this->runVm('-r', 'exit(2);');
        $this->assertSame(2, $result['exit']);
    }

    public function testDieStringEchoesAndExitsZero(): void
    {
        $result = $this->runVm('-r', 'die("bye");');
        $this->assertSame(0, $result['exit']);
        $this->assertSame('bye', $result['stdout']);
    }

    public function testHttpResponseCodeSurvivesExit(): void
    {
        $code = <<<'PHP'
<?php
http_response_code(404);
exit(0);
echo "never";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'exit_status.php');
        try {
            $runtime->run($block);
            $this->fail('Expected ScriptExit');
        } catch (VM\ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame(404, Web\ResponseContext::getStatus());
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runVm(string ...$args): array
    {
        $vm = realpath(__DIR__.'/../../bin/vm.php');
        $this->assertNotFalse($vm);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            array_merge([PHP_BINARY, $vm], $args),
            $descriptorSpec,
            $pipes,
            dirname(__DIR__, 2)
        );
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => $stdout !== false ? $stdout : '',
            'stderr' => '',
        ];
    }
}
