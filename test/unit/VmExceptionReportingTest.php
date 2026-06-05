<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #6357: uncaught Throwable formatting must not secondary-fatal on uninitialized slots. */
final class VmExceptionReportingTest extends TestCase
{
    public function testUncaughtExitEnumCasePrintsSingleErrorMessage(): void
    {
        $stderr = $this->runVmCli(<<<'PHP'
<?php
enum E: string { case A = 'x'; }
exit(E::A);
PHP
        );
        $this->assertStringContainsString(
            'Uncaught Error: Object of class E could not be converted to string',
            $stderr
        );
        $this->assertStringNotContainsString('Variable::$string', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

    public function testUncaughtBuiltinTypeErrorPrintsOnce(): void
    {
        $stderr = $this->runVmCli(<<<'PHP'
<?php
class C {}
$o = new C();
array_key_exists('k', $o);
PHP
        );
        $this->assertStringContainsString('Uncaught TypeError:', $stderr);
        $this->assertStringNotContainsString('Variable::$string', $stderr);
        $this->assertStringNotContainsString('ExceptionSupport.php', $stderr);
    }

    private function runVmCli(string $code): string
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_vm_exc_');
        $this->assertNotFalse($tmp);
        $script = $tmp.'.php';
        rename($tmp, $script);
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
        proc_close($proc);
        @unlink($script);
        $this->assertIsString($stderr);

        return $stderr;
    }
}
