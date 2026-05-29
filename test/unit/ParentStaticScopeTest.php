<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #3093 */
final class ParentStaticScopeTest extends TestCase
{
    public function testParentClassConstantInInstanceMethod(): void
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
        $this->assertStringContainsString('A', $combined);
        $this->assertStringNotContainsString('not supported', $combined);
    }

    public function testParentStaticPropertyFetchAndAssign(): void
    {
        $bin = realpath(__DIR__ . '/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $code = <<<'PHP'
<?php
class A {
    public static $x = 1;
}
class B extends A {
    public function f(): void {
        echo parent::$x;
        parent::$x = 2;
        echo parent::$x;
    }
}
(new B())->f();
PHP;
        $combined = $this->runVm($bin, $code);
        $this->assertStringContainsString('12', $combined);
        $this->assertStringNotContainsString('not supported', $combined);
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
