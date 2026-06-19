<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** throw in class constant expressions (#6580). */
final class ThrowInClassConstCompileCheckTest extends TestCase
{
    public function testThrowInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public const X = throw new Exception('x');
}
PHP);
    }

    public function testThrowInClassConstErrorsBeforeNewWithoutParensCheck(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(ThrowInClassConstCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = throw new Exception('x');
}
PHP, 'throw_before_new.php');
    }

    public function testThrowInInterfaceConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
interface I {
    public const X = throw new Exception('x');
}
PHP);
    }

    public function testFuncCallInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
final class C {
    public const X = strlen('hi');
}
PHP);
    }

    public function testFuncCallInEnumCaseCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
enum E: int {
    case A = max(1, 2);
}
PHP);
    }

    public function testFuncCallInGlobalConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const C = array_find([1, 2, 3], fn($v) => $v > 1);
PHP);
    }

    public function testFuncCallInGlobalConstWithStrlenCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const C = strlen('hi');
PHP);
    }

    public function testClosureInGlobalConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const C = function () { return 1; };
PHP);
    }

    public function testStaticCallInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
final class C {
    public const X = self::f();
    public static function f(): int { return 1; }
}
PHP);
    }

    public function testLegalClassConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = 1 + 2;
}
PHP, 'class_const_ok.php');
        $this->assertNotNull($block);
    }

    public function testRuntimeThrowExpressionStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function f(): int {
    return throw new Exception('x');
}
PHP, 'throw_expr_ok.php');
        $this->assertNotNull($block);
    }

    private function expectCompileError(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(ThrowInClassConstCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'throw_class_const.php');
    }
}
