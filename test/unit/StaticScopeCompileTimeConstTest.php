<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Compiler\ThrowInClassConstCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #31145 */
final class StaticScopeCompileTimeConstTest extends TestCase
{
    public function testClassConstStaticFetchIsCompileFatal(): void
    {
        $this->assertStaticScopeFatal(<<<'PHP'
<?php
class C {
    const X = 1;
    const Y = static::X;
}
PHP, 'static_scope_class_const.php');
    }

    public function testParamDefaultStaticFetchIsCompileFatal(): void
    {
        $this->assertStaticScopeFatal(<<<'PHP'
<?php
class C {
    const X = 1;
    function f($a = static::X) {}
}
PHP, 'static_scope_param_default.php');
    }

    public function testPropertyDefaultStaticFetchIsCompileFatal(): void
    {
        $this->assertStaticScopeFatal(<<<'PHP'
<?php
class C {
    const X = 1;
    public $a = static::X;
}
PHP, 'static_scope_property_default.php');
    }

    public function testFileLevelParamDefaultStaticFetchIsCompileFatal(): void
    {
        $this->assertStaticScopeFatal(<<<'PHP'
<?php
function f($a = static::X) {}
PHP, 'static_scope_file_param.php');
    }

    public function testSelfAndParentConstExprsStillCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { const X = 2; }
class C extends A {
    const Y = self::X;
    const Z = parent::X;
    function f($a = self::X) { return $a; }
    public $p = self::X;
}
echo C::Y, C::Z, (new C)->p, (new C)->f();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'static_scope_self_parent_ok.php'));
        self::assertSame('2222', ob_get_clean());
    }

    public function testMethodBodyStaticFetchStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    const X = 1;
    static function f() { return static::X; }
}
class B extends A { const X = 2; }
echo B::f();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'static_scope_method_body_ok.php'));
        self::assertSame('2', ob_get_clean());
    }

    private function assertStaticScopeFatal(string $code, string $filename): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile($code, $filename);
            $this->fail('Expected CompileFatal for static:: in compile-time constant');
        } catch (CompileFatal $e) {
            self::assertSame(ThrowInClassConstCompileCheck::STATIC_SCOPE_MESSAGE, $e->getMessage());
            self::assertStringStartsWith('PHP Fatal error:', $e->zendStderrLine());
        }
    }
}
