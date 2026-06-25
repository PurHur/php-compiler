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

    public function testClassTraitPropertyConflictFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public $x = 1; }
class C { use T; public $x = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'C and T define the same property ($x) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_class_property_conflict.php');
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
}
