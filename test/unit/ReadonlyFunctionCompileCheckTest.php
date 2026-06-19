<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ReadonlyFunctionRejector;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #10012 */
final class ReadonlyFunctionCompileCheckTest extends TestCase
{
    public function testTopLevelReadonlyFunctionFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyFunctionRejector::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
readonly function f(): void {
    echo "ok\n";
}
f();
PHP, 'readonly_function_decl.php');
    }

    public function testReadonlyClosureFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyFunctionRejector::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
$x = 1;
readonly function () use ($x) {};
PHP, 'readonly_function_capture.php');
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
