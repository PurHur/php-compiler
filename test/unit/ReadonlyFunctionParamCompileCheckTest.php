<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6291 */
final class ReadonlyFunctionParamCompileCheckTest extends TestCase
{
    public function testReadonlyStandaloneFunctionParamFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(readonly string $x) {
    echo $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property outside a constructor');
        $runtime->parseAndCompile($code, 'readonly_function_param.php');
    }

    public function testReadonlyNonConstructorMethodParamFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function m(readonly string $x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property outside a constructor');
        $runtime->parseAndCompile($code, 'readonly_method_param.php');
    }

    public function testReadonlyClosureParamFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$f = function (readonly string $x) {
    return $x;
};
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property outside a constructor');
        $runtime->parseAndCompile($code, 'readonly_closure_param.php');
    }

    public function testPromotedReadonlyConstructorParamStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public readonly string $x) {}
}
echo (new C('hi'))->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_promoted_ctor.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }

    public function testReadonlyConstructorParamWithoutPromotionStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(readonly string $x) {
        echo $x, "\n";
    }
}
new C('ok');
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_ctor_param.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
