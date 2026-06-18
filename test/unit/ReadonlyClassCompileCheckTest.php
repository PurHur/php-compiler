<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3551 */
final class ReadonlyClassCompileCheckTest extends TestCase
{
    public function testNonReadonlyChildOfReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class A {}
class B extends A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-readonly class B cannot extend readonly class A');
        $runtime->parseAndCompile($code, 'non_readonly_child.php');
    }

    public function testReadonlyChildOfNonReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
readonly class R extends C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly class R cannot extend non-readonly class C');
        $runtime->parseAndCompile($code, 'readonly_child.php');
    }

    /** @covers issue #9653 */
    public function testReadonlyClassPropertyDefaultFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class R2 {
    public int $x = 1;
    public string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly property R2::$x cannot have default value');
        $runtime->parseAndCompile($code, 'readonly_default.php');
    }

    /** @covers issue #9653 */
    public function testReadonlyClassConstructorPromotedPropertyStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class R3 {
    public function __construct(public int $x = 1) {}
}
echo (new R3)->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_ctor_promote.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testReadonlyPropertyDefaultOnNormalClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly property C::$x cannot have default value');
        $runtime->parseAndCompile($code, 'readonly_prop_default.php');
    }

    /** @covers issue #6862 */
    public function testReadonlyClassStaticPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class R {
    public static string $label = 'shared';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly class R cannot declare static properties');
        $runtime->parseAndCompile($code, 'readonly_class_static.php');
    }

    public function testStaticReadonlyPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static readonly int $p;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Static property C::$p cannot be readonly');
        $runtime->parseAndCompile($code, 'static_readonly.php');
    }

    public function testStaticMutablePropertyStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static int $p;
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'static_mutable.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #6991 */
    public function testReadonlyAnonymousClassCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new readonly class {
    public int $x = 1;
};
var_export($o->x);
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_anon_default.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    /** @covers issue #6724 — ZEND_ACC_ANON_READONLY via per-property readonly on anonymous class */
    public function testReadonlyPropertyOnAnonymousClassDefaultCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new class {
    public readonly int $x = 1;
};
var_export($o->x);
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_prop_anon_default.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testReadonlyExtendsReadonlyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class A {}
readonly class B extends A {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'readonly_chain.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #8967 */
    public function testJitReadonlyExtendsNonReadonlyParentFailsWithInheritanceMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class B {}
readonly class R extends B {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly class R cannot extend non-readonly class B');
        $runtime->parseAndCompile(\PHPCompiler\JitMcjitEmbed::prepareClassless($code), 'jit_readonly_extends.php');
    }

    /** @covers issue #8967 */
    public function testJitNonReadonlyExtendsReadonlyParentFailsWithInheritanceMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class R {}
class C extends R {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-readonly class C cannot extend readonly class R');
        $runtime->parseAndCompile(\PHPCompiler\JitMcjitEmbed::prepareClassless($code), 'jit_nonreadonly_extends.php');
    }

    /** @covers issue #7170 */
    public function testEvalReadonlyExtendsKnownNonReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $main = <<<'PHP'
<?php
class ParentNormal {}
PHP;
        $runtime->run($runtime->parseAndCompile($main, 'boot.php'));

        $evalCode = <<<'PHP'
<?php
readonly class ChildReadonly extends ParentNormal {
    public function __construct(public int $x = 1) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Readonly class ChildReadonly cannot extend non-readonly class ParentNormal');
        $runtime->parseAndCompile($evalCode, "eval()'d code");
    }

    /** @covers issue #7170 */
    public function testEvalNonReadonlyExtendsReadonlyParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class ParentReadonly {}
class ChildNormal extends ParentReadonly {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-readonly class ChildNormal cannot extend readonly class ParentReadonly');
        $runtime->parseAndCompile($code, "eval()'d code");
    }

    /** @covers issue #7299 */
    public function testAllowDynamicPropertiesOnReadonlyClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\AllowDynamicProperties]
readonly class R {
    public function __construct(public int $x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot apply #[AllowDynamicProperties] to readonly class R');
        $runtime->parseAndCompile($code, 'allow_dynamic_readonly.php');
    }

    /** @covers issue #7367 */
    public function testReadonlyParentPropertyWidenedToNonReadonlyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public readonly string $x;
    public function __construct(string $x) { $this->x = $x; }
}
class C extends P {
    public string $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare readonly property P::$x as non-readonly C::$x');
        $runtime->parseAndCompile($code, 'readonly_widen_nonreadonly.php');
    }

    /** @covers issue #7359 */
    public function testNonReadonlyParentPropertyNarrowedToReadonlyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class B {
    public int $x = 1;
}
class C extends B {
    public readonly int $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare non-readonly property B::$x as readonly C::$x');
        $runtime->parseAndCompile($code, 'readonly_narrow.php');
    }
}
