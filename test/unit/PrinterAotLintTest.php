<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint gate for lib/Printer.php (string concat with null CFG operands).
 */
final class PrinterAotLintTest extends TestCase
{
    public function testLibPrinterParseAndCompile(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/lib/Printer.php';
        $runtime = new Runtime(Runtime::MODE_AOT);
        $block = $runtime->parseAndCompile((string) file_get_contents($path), $path);
        $this->assertNotNull($block);
    }

    public function testLibPrinterCompileLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/lib/Printer.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for lib/Printer.php'
        );
    }
}
