<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3385 */
final class AbstractInstantiateTest extends TestCase
{
    public function testAbstractClassInstantiationIsCompileTimeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {}
new A();
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot instantiate abstract class A');
        $runtime->parseAndCompile($code, 'abstract_instantiate.php');
    }

    public function testAnonymousClassMayExtendAbstractParent(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    public function f(): int { return 1; }
}
$o = new class extends A {};
echo $o->f();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'abstract_instantiate_anon.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testDynamicAbstractInstantiationIsRuntimeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {}
$c = 'A';
new $c();
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot instantiate abstract class A');
        $runtime->run($runtime->parseAndCompile($code, 'abstract_instantiate_dynamic.php'));
    }

    public function testClassAbstractFlagFromPhpCfg(): void
    {
        $this->assertTrue(\PHPCompiler\VM\ClassAbstract::fromClassFlags(
            \PhpParser\Node\Stmt\Class_::MODIFIER_ABSTRACT
        ));
    }
}
