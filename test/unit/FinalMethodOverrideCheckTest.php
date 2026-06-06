<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4263 */
final class FinalMethodOverrideCheckTest extends TestCase
{
    public function testOverrideFinalParentMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    final public function foo(): void {}
}
class Child extends Base {
    public function foo(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method Base::foo()');
        $runtime->parseAndCompile($code, 'override_final.php');
    }

    public function testNonFinalOverrideCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    public function foo(): void { echo "ok\n"; }
}
(new Child())->foo();
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testTraitMethodCannotOverrideFinalParentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    final public function foo(): void {}
}
trait T {
    public function foo(): void {}
}
class Child extends Base {
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method Base::foo()');
        $runtime->parseAndCompile($code, 'trait_override_final.php');
    }
}
