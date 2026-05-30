<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3548 */
final class AbstractPrivateMethodCheckTest extends TestCase
{
    public function testAbstractPrivateMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract private function f(): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Abstract function A::f() cannot be declared private');
        $runtime->parseAndCompile($code, 'abstract_private.php');
    }

    public function testAbstractPublicMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public function f(): void;
}
class C extends A {
    public function f(): void {
        echo "ok\n";
    }
}
(new C())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_public.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testAbstractProtectedMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract protected function f(): void;
}
class C extends A {
    protected function f(): void {}
}
echo C::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_protected.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("C\n", ob_get_clean());
    }

    public function testLintAbstractPrivateMethodReportsCompileFatal(): void
    {
        $runtime = new Runtime();
        $linter = new \PHPCompiler\Lint\Linter($runtime);
        $code = <<<'PHP'
<?php
abstract class A {
    abstract private function f(): void;
}
PHP;
        $issues = $linter->lintSource($code, 'abstract_private.php');
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString(
            'Abstract function A::f() cannot be declared private',
            $issues[0]->message
        );
        $this->assertGreaterThan(0, $issues[0]->line);
    }
}
