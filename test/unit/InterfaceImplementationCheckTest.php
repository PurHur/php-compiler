<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3386 */
final class InterfaceImplementationCheckTest extends TestCase
{
    public function testMissingInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class C implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C contains 1 abstract method');
        $this->expectExceptionMessage('I::f');
        $runtime->parseAndCompile($code, 'missing.php');
    }

    public function testImplementedInterfaceCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class C implements I {
    public function f(): int {
        return 1;
    }
}
echo C::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("C\n", ob_get_clean());
    }

    public function testAbstractClassMayLeaveInterfaceMethodsUnimplemented(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
abstract class C implements I {}
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_ok.php');
        $this->assertNotNull($block);
    }

    public function testParentImplementationSatisfiesChild(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class Base implements I {
    public function f(): int {
        return 1;
    }
}
class Child extends Base {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'inherit_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
