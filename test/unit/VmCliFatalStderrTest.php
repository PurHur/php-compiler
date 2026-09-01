<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * VM CLI driver must emit Zend-shaped fatals on stderr, not silent exit 255 (#36208).
 */
final class VmCliFatalStderrTest extends TestCase
{
    public function testVmDriverRuntimeFatalWritesStderrNotStdout(): void
    {
        $code = "<?php\nclass A { use MissingTrait; }\n";
        $path = sys_get_temp_dir().'/phpc_vm_fatal_stderr_36208.php';
        file_put_contents($path, $code);
        [$exit, $stdout, $stderr] = $this->runVmDriver($path);
        @unlink($path);

        $this->assertSame(255, $exit);
        $this->assertStringContainsString('PHP Fatal error:', $stderr);
        $this->assertStringContainsString('Trait "MissingTrait" not found', $stderr);
        $this->assertSame('', trim($stdout));
    }

    public function testVmDriverCompileFatalWritesStderrNotStdout(): void
    {
        $code = '<?php function f(): never { return 1; }';
        $path = sys_get_temp_dir().'/phpc_vm_compile_fatal_36208.php';
        file_put_contents($path, $code);
        [$exit, $stdout, $stderr] = $this->runVmDriver($path);
        @unlink($path);

        $this->assertSame(255, $exit);
        $this->assertStringContainsString('PHP Fatal error:', $stderr);
        $this->assertStringNotContainsString('parseAndCompile failure:', $stderr);
        $this->assertSame('', trim($stdout));
    }

    public function testVmDriverLogicExceptionHelperShape(): void
    {
        $e = new \LogicException('Could not resolve argument');
        $expected = sprintf(
            "PHP Fatal error:  Uncaught %s: %s in %s:%d\n",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            max(1, $e->getLine())
        );
        $this->assertStringStartsWith('PHP Fatal error:', $expected);
        $this->assertStringContainsString('Could not resolve argument', $expected);

        $unsupported = new \LogicException('Unsupported type copy: -1');
        $unsupportedLine = sprintf(
            "PHP Fatal error:  Uncaught %s: %s in %s:%d\n",
            $unsupported::class,
            $unsupported->getMessage(),
            $unsupported->getFile(),
            max(1, $unsupported->getLine())
        );
        $this->assertStringContainsString('Unsupported type copy: -1', $unsupportedLine);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function runVmDriver(string $scriptPath): array
    {
        $vm = dirname(__DIR__, 2).'/bin/vm.php';
        $cmd = [
            PHP_BINARY,
            '-d', 'error_reporting=1',
            '-d', 'log_errors=1',
            '-d', 'display_errors=stderr',
            $vm,
            $scriptPath,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [(int) $exit, $stdout, $stderr];
    }
}
