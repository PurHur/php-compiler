<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3385 / #25787 — abstract instantiate is runtime Error, not compile fatal */
final class AbstractInstantiateTest extends TestCase
{
    public function testAbstractClassInstantiationIsRuntimeFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {}
new A();
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_instantiate.php');
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate abstract class A');
        $runtime->run($block);
    }

    public function testDeadAbstractNewDoesNotFailParseAndCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {}
echo "alive\n";
if (false) {
    new A();
}
echo "done\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'abstract_new_dead.php'));
        $out = ob_get_clean();
        $this->assertSame("alive\ndone\n", $out);
    }

    public function testAnonymousClassMayExtendAbstractParent(): void
    {
        $this->markTestSkipped('Anonymous class extends abstract parent requires php-cfg anonymous class lowering (#1233)');
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
        $this->expectException(\Error::class);
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
