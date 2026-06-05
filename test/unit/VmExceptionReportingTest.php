<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #6357: uncaught VM errors must not secondary-fatal in ExceptionSupport. */
final class VmExceptionReportingTest extends TestCase
{
    public function testUncaughtBuiltinTypeErrorPrintsOnceWithoutExceptionSupportStack(): void
    {
        $stderr = $this->runVmCliFile('<?php
class C {}
$o = new C();
array_key_exists(\'k\', $o);
');
        $this->assertStringContainsString('Uncaught TypeError:', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
        $this->assertStringNotContainsString('Variable::$string must not be accessed before initialization', $stderr);
    }

    public function testUncaughtDispatchVmErrorFatalAtUserSite(): void
    {
        $stderr = $this->runVmCliFile('<?php
$w = WeakReference::create(new stdClass);
', 'weakref_uncaught.php');
        $this->assertStringContainsString('Uncaught Error:', $stderr);
        $this->assertStringContainsString('Non-static method WeakReference::create()', $stderr);
        $this->assertStringContainsString('weakref_uncaught.php', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
        $this->assertStringNotContainsString('Variable::$string must not be accessed before initialization', $stderr);
    }

    private function runVmCliFile(string $code, ?string $basename = null): string
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_vm_exc_');
        $this->assertNotFalse($tmp);
        $script = $tmp . '.php';
        rename($tmp, $script);
        if (null !== $basename) {
            $named = dirname($script) . '/' . $basename;
            rename($script, $named);
            $script = $named;
        }
        file_put_contents($script, $code);
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $bin, $script], $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);
        $this->assertNotSame(0, $exit);
        $this->assertIsString($stderr);

        return $stderr;
    }
}
