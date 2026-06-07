<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\ReadonlyFunctionCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7428 */
final class ReadonlyFunctionCompileCheckTest extends TestCase
{
    public function testReadonlyFunctionCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly function f(): void {
    echo "ok\n";
}
f();
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_function_decl.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testMutableCaptureFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$x = 1;
readonly function () use ($x) {};
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot bind non-readonly variable $x in readonly closure');
        $runtime->parseAndCompile($code, 'readonly_function_capture.php');
    }

    public function testReadonlyClassMethodStillAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class C {
    function m(): void {
        echo "ok\n";
    }
}
(new C())->m();
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_class_method.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
