<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issues #6357 / #6358: uncaught VM errors must not secondary-fatal in ExceptionSupport. */
final class VmExceptionReportingTest extends TestCase
{
    public function testUncaughtExitEnumCasePrintsSingleErrorMessage(): void
    {
        $stderr = $this->runVmCliFile(<<<'PHP'
<?php
enum E: string { case A = 'x'; }
exit(E::A);
PHP
        );
        $this->assertStringContainsString(
            'Uncaught Error: Object of class E could not be converted to string',
            $stderr
        );
        $this->assertStringNotContainsString('Variable::$string must not be accessed before initialization', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

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
stdClass::undefined();
', 'dispatch_vm_error_uncaught.php');
        $this->assertStringContainsString('Uncaught Error:', $stderr);
        $this->assertStringContainsString('Call to undefined method stdClass::undefined()', $stderr);
        $this->assertStringContainsString('dispatch_vm_error_uncaught.php', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
        $this->assertStringNotContainsString('Variable::$string must not be accessed before initialization', $stderr);
    }

    public function testUncaughtFinallyThrowPrintsNextExceptionChain(): void
    {
        $stderr = $this->runVmCliFile(<<<'PHP'
<?php
try {
    throw new Exception('inner');
} finally {
    throw new Exception('finally');
}
PHP
        );
        $this->assertStringContainsString('Uncaught Exception: inner', $stderr);
        $this->assertStringContainsString('Next Exception: finally', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

    public function testUncaughtReadonlyPropertyWriteFatalAtUserSite(): void
    {
        $stderr = $this->runVmCliFile(<<<'PHP'
<?php
class C {
    public function __construct(public readonly int $x) {}
}
$c = new C(1);
$c->x = 2;
PHP
            , 'readonly_uncaught_site.php');
        $this->assertStringContainsString('Cannot modify readonly property C::$x', $stderr);
        $this->assertStringContainsString('readonly_uncaught_site.php', $stderr);
        $this->assertMatchesRegularExpression('/readonly_uncaught_site\.php:6\b/', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

    public function testCaughtReadonlyPropertyWriteReportsUserSite(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_vm_ro_');
        $this->assertNotFalse($tmp);
        $script = $tmp . '.php';
        rename($tmp, $script);
        file_put_contents($script, <<<'PHP'
<?php
class C {
    public function __construct(public readonly int $x) {}
}
try {
    $c = new C(1);
    $c->x = 2;
} catch (Error $e) {
    echo basename($e->getFile()) . ':' . $e->getLine() . "\n";
    echo $e->getMessage() . "\n";
}
PHP
        );
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$php, $bin, $script], $descriptor, $pipes, dirname(__DIR__, 2));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($script);
        $this->assertSame(0, $exit);
        $this->assertIsString($stdout);
        $this->assertStringContainsString('Cannot modify readonly property C::$x', $stdout);
        $this->assertMatchesRegularExpression('/^[^\n]+:7\n/', $stdout);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stdout);
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
