<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6633 #7414 #11473 */
final class NeverParamTypeTest extends TestCase
{
    public function testStandaloneNeverParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function acceptsNever(never $value): void {}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param.php');
    }

    public function testNeverParamOnAbstractMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class C {
    abstract public function f(never $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param_abstract.php');
    }

    public function testNeverParamOnInterfaceMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(never $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param_interface.php');
    }

    public function testNeverInUnionParamCompilesAndAcceptsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x): int {
    return $x;
}
echo f(42);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_union_param.php'));
        $this->assertSame('42', ob_get_clean());
    }

    public function testNeverInUnionParamRejectsString(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x): void {}
try {
    f('bad');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_union_param_bad.php'));
        $this->assertSame(
            "TypeError: Argument must be of type int|never, string given\n",
            ob_get_clean()
        );
    }

}
