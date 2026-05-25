<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #1858 */
final class ParentStaticScopeTest extends TestCase
{
    public function testParentClassConstantRejectedAtRuntime(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = <<<'PHP'
<?php
class A {}
class B extends A {
    public function f(): void {
        echo parent::class;
    }
}
(new B())->f();
PHP;
        $combined = $this->runVm($bin, $code);
        $this->assertStringContainsString('parent::class is not supported', $combined);
    }

    private function runVm(string $bin, string $code): string
    {
        $cmd = [PHP_BINARY, $bin];
        $pipes = [];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($bin));
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);

        return ($stdout !== false ? $stdout : '') . ($stderr !== false ? $stderr : '');
    }
}
