<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #11381 */
final class ReturnTypeFatalLineTest extends TestCase
{
    public function testGeneratorScalarReturnFatalUsesReturnStatementLine(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $repro = $repoRoot.'/test/repro/maintainer_gap_generator_return_scalar.php';
        $cmd = [PHP_BINARY, $repoRoot.'/bin/vm.php', $repro];
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertNotSame(0, $exit);
        $this->assertIsString($stderr);
        $this->assertStringContainsString('Return value must be of type Generator', $stderr);
        $this->assertStringContainsString('maintainer_gap_generator_return_scalar.php:5', $stderr);
        $this->assertStringNotContainsString('maintainer_gap_generator_return_scalar.php:8', $stderr);
    }
}
