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
}
