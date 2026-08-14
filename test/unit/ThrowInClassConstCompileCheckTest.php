<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** throw/cast/silence/match in class constant expressions (#6580, #24904, #24905, #24947). */
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

    /** Non-static Closure stays invalid even on PROFILE=8.5 (#26240). */
    public function testNonStaticClosureInGlobalConstCompileErrorsOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->expectCompileError(<<<'PHP'
<?php
const C = function () { return 1; };
PHP);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** Static Closure / arrow / FCC allowed in const exprs on PROFILE=8.5 (#26240). */
    public function testStaticClosureAndFccInConstAllowedOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
const C = static fn(int $x): int => $x + 1;
const D = strlen(...);
const E = static function (int $x): int { return $x + 1; };
class K {
    public const F = static fn(string $s): int => strlen($s);
}
echo (C)(2), ',', (D)('ab'), ',', (E)(2), ',', (K::F)('xy');
PHP, 'const_closure_fcc_85.php');
            $this->assertNotNull($block);
            ob_start();
            $runtime->run($block);
            $this->assertSame('3,2,3,2', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testStaticClosureUseInConstCompileErrorsOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->expectCompileError(<<<'PHP'
<?php
$a = 1;
const C = static function () use ($a) { return $a; };
PHP);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testMatchInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = match(1) { 1 => "one", default => "x" };
}
PHP);
    }

    public function testMatchInTypedClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public const int X = match (2) { 1 => 10, 2 => 20, default => 0 };
}
PHP);
    }

    public function testMatchInGlobalConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const X = match(1) { 1 => "one", default => "x" };
PHP);
    }

    public function testMatchDefaultOnlyInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = match(1) { default => "x" };
}
PHP);
    }

    public function testCastIntInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = (int) "5";
}
PHP);
    }

    public function testCastStringInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = (string) 1;
}
PHP);
    }

    public function testCastBoolInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = (bool) 1;
}
PHP);
    }

    public function testObjectCastInClassConstCompileErrorsEvenOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = (object) [];
}
PHP);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCastIntInClassConstAllowedOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class A {
    public const X = (int) "5";
}
PHP, 'class_const_cast_int_85.php');
            $this->assertNotNull($block);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCastInGlobalAndTypedClassConstAllowedOn85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
const X = (int) "42";
class C {
    public const int Y = (int) "7";
}
PHP, 'const_cast_85.php');
            $this->assertNotNull($block);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSilenceInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = @1;
}
PHP);
    }

    public function testSilenceExprInClassConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class A {
    public const X = @(1 + 2);
}
PHP);
    }

    public function testCastInGlobalConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const X = (int) "5";
PHP);
    }

    public function testSilenceInGlobalConstCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
const X = @1;
PHP);
    }

    public function testTernaryWithIdenticalInClassConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class A {
    public const X = (1 === 1) ? "a" : "b";
}
PHP, 'class_const_ternary_identical_ok.php');
        $this->assertNotNull($block);
    }

    /** php-cfg synthetic Cast\Bool_ on &&/|| must not be treated as user cast (#25839). */
    public function testLogicalAndOrInClassConstStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class AndConst {
    public const X = true && false;
}
class OrConst {
    public const X = false || true;
}
class TernaryConst {
    public const X = 1 < 2 ? 3 : 4;
}
PHP, 'class_const_logical_ok.php');
        $this->assertNotNull($block);
    }

    /** User (bool) cast in class const stays invalid on ≤8.4 even beside && (#25839 / #24905). */
    public function testUserBoolCastInLogicalClassConstStillErrorsOn84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->expectCompileError(<<<'PHP'
<?php
class C {
    public const X = true && (bool) 1;
}
PHP);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
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

    public function testStaticScopeClassConstIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ThrowInClassConstCompileCheck::STATIC_SCOPE_MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    const X = 1;
    const Y = static::X;
}
PHP, 'static_scope_class_const.php');
    }

    private function expectCompileError(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(ThrowInClassConstCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'throw_class_const.php');
    }
}
