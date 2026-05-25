<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #1885: nested return <call>() propagates through user function frames. */
final class NestedReturnVmTest extends TestCase
{
    public function testSingleFunctionReturn(): void
    {
        $this->assertVmOutput(
            '<?php
            function f() { return "hi"; }
            echo f();
            ',
            'hi'
        );
    }

    public function testNestedFunctionReturn(): void
    {
        $this->assertVmOutput(
            '<?php
            function f() { return "hi"; }
            function g() { return f(); }
            echo g();
            ',
            'hi'
        );
    }

    public function testReturnAfterInnerVoidCall(): void
    {
        $this->assertVmOutput(
            '<?php
            function f() {}
            function g() { f(); return "after"; }
            echo g();
            ',
            'after'
        );
    }

    public function testLateStaticBindingReturnCall(): void
    {
        $this->assertVmOutput(
            '<?php
            class Greeter {
                public static function tag(): string { return "hi"; }
                public function viaStatic(): string { return static::tag(); }
            }
            $g = new Greeter();
            echo $g->viaStatic();
            ',
            'hi'
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
