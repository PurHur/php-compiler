<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7300 */
final class CompileTimeAttributeTest extends TestCase
{
    public function testRejectsCompileTimeOnFunction(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\CompileTime]
function f(): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "CompileTime" cannot target function (allowed targets: class constant, constant)'
        );
        $runtime->parseAndCompile($code, 'compile_time_function.php');
    }

    public function testRejectsCompileTimeOnClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\CompileTime]
class C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "CompileTime" cannot target class (allowed targets: class constant, constant)'
        );
        $runtime->parseAndCompile($code, 'compile_time_class.php');
    }

    public function testAllowsCompileTimeOnClassConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    #[\CompileTime]
    public const X = 7;
}
echo C::X, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'compile_time_class_const.php'));
        $this->assertSame("7\n", ob_get_clean());
    }

    public function testRejectsCompileTimeOnParameter(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Attribute "CompileTime" cannot target parameter (allowed targets: class constant, constant)'
        );

        AttributeNames::assertCompileTimeConstTargetOnly(['CompileTime'], 'parameter');
    }
}
