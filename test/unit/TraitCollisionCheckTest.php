<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3416 */
final class TraitCollisionCheckTest extends TestCase
{
    public function testTraitMethodCollisionFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 2; } }
class C { use T1, T2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Trait method T2::f has not been applied');
        $this->expectExceptionMessage('collision with T1::f');
        $runtime->parseAndCompile($code, 'collision.php');
    }

    public function testClassMethodOverridesTraitCollision(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 2; } }
class C {
    use T1, T2;
    public function f(): int {
        return 3;
    }
}
echo (new C())->f(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'override.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("3\n", ob_get_clean());
    }

    public function testSingleTraitUseUnchanged(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } }
class C { use T; }
echo (new C())->f(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'single.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testDuplicateTraitInUseListDedupesSilently(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function foo(): int { return 1; } }
class C { use T, T; }
echo (new C())->foo(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'duplicate_use.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testDuplicateTraitInSeparateUseStatementsDedupesSilently(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function foo(): int { return 1; } }
class C {
    use T;
    use T;
}
echo (new C())->foo(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'duplicate_use_separate.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    /** Trait methods override inherited parent methods (#19630, zend_traits.c). */
    public function testTraitMethodOverridesInheritedParentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function f() { return 'base'; } }
trait T { public function f() { return parent::f() . '+T'; } }
class A extends Base { use T; }
echo (new A)->f(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_overrides_parent.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("base+T\n", ob_get_clean());
    }

    /** Two traits colliding on a parent-overridden name must still fail (#19630). */
    public function testTraitCollisionOnParentMethodNameStillFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function f(): int { return 0; } }
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 2; } }
class C extends Base { use T1, T2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Trait method T2::f has not been applied');
        $this->expectExceptionMessage('collision with T1::f');
        $runtime->parseAndCompile($code, 'trait_collision_parent.php');
    }

    public function testClassTraitPropertyConflictFailsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_property_conflict.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->run($block, false);
    }

    public function testIdenticalClassTraitPropertyMerges(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 1; }
echo (new C())->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_class_property_identical.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testTraitPropertyMergeWithoutRedefinitionStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; }
echo (new C())->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_property_merge.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testTraitAbstractPropertyHooksAllowClassConcreteOverride(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks require PHP_COMPILER_PROFILE=8.4');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public string $x { get; set; }
}
abstract class C {
    use T;
}
class D extends C {
    public string $x {
        get => $this->__x;
        set(string $v) { $this->__x = $v; }
    }
    private string $__x = '';
}
$c = new D();
$c->x = 'hi';
echo $c->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_abstract_property_hook_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }

    public function testTraitClassSameHookedPropertyComposeFatal(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks require PHP_COMPILER_PROFILE=8.4');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public int $x { get; }
}
class C {
    use T;
    public int $x {
        get => 5;
    }
}
echo (new C)->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_hook_compose_conflict.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  C and T define the same hooked property ($x) in the composition of C. '
            .'Conflict resolution between hooked properties is currently not supported. Class was composed'
        );
        $runtime->run($block);
    }
}
