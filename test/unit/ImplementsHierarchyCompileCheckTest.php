<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #12971 */
final class ImplementsHierarchyCompileCheckTest extends TestCase
{
    public function testClassImplementsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
class B implements A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B cannot implement A - it is not an interface');
        $runtime->parseAndCompile($code, 'implements_class.php');
    }

    public function testEnumImplementsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
enum E implements C { case A; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('E cannot implement C - it is not an interface');
        $runtime->parseAndCompile($code, 'enum_implements_class.php');
    }

    public function testClassExtendsInterfaceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
class C extends I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C cannot extend interface I');
        $runtime->parseAndCompile($code, 'extends_interface.php');
    }

    public function testInterfaceExtendsClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {}
interface I extends C {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('I cannot implement C - it is not an interface');
        $runtime->parseAndCompile($code, 'interface_extends_class.php');
    }

    public function testValidHierarchyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
class C implements I {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'valid.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** @covers issue #9722 */
    public function testClassImplementsClassAfterRuntimeStatementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
try {
    echo "ok\n";
} catch (Throwable $e) {
}
class B implements A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B cannot implement A - it is not an interface');
        $runtime->parseAndCompile($code, 'nested.php');
    }
}
