<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\AbstractPropertyHookCheck;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Variable;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

final class AbstractPropertyHookCheckTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }



public function testIsAbstractHookPropertyOnDeclaringClass(): void
    {
        $ctx = (new Runtime())->vmContext;
        $a = new ClassEntry('A');
        $a->isAbstract = true;
        $prop = new ClassProperty('x', null, new Variable());
        $a->properties[] = $prop;
        $ctx->classes['a'] = $a;
        $ctx->propertyHookRegistry['a']['x'] = ['requiresGet' => true, 'abstract' => true];

        self::assertTrue(AbstractPropertyHookCheck::isAbstractHookProperty($a, $prop, $ctx));
    }

    public function testImplementedHookIsNotAbstract(): void
    {
        $ctx = (new Runtime())->vmContext;
        $b = new ClassEntry('B');
        $prop = new ClassProperty('x', null, new Variable());
        $prop->getHookMethodLc = 'getx';
        $b->properties[] = $prop;
        $ctx->classes['b'] = $b;
        $ctx->propertyHookRegistry['b']['x'] = ['requiresGet' => true, 'get' => 'getX'];

        self::assertFalse(AbstractPropertyHookCheck::isAbstractHookProperty($b, $prop, $ctx));
    }

    public function testMissingParentAbstractHookOnConcreteClass(): void
    {
        $ctx = (new Runtime())->vmContext;
        $a = new ClassEntry('A');
        $a->isAbstract = true;
        $ctx->classes['a'] = $a;
        $anon = new ClassEntry('A@anonymous/test.php:5$0');
        $anon->parentLc = 'a';
        $ctx->classes[strtolower($anon->name)] = $anon;
        $ctx->propertyHookRegistry['a']['x'] = ['requiresGet' => true, 'abstract' => true];

        $missing = AbstractPropertyHookCheck::missingForClass($anon, $ctx);
        self::assertSame([['A', '$x::get']], $missing);
    }

    public function testDeferredAnonymousClassMissingAbstractHookFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public string $x { get; }
}
new class extends A {};
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('abstract method');
        $this->expectExceptionMessage('A::$x::get');
        $runtime->parseAndCompile($code, 'anon.php');
    }

    public function testTraitAbstractPropertyHookMissingOnUsingClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    public string $x { get; set; }
}
class C {
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('abstract method');
        $this->expectExceptionMessage('T::$x::get');
        $this->expectExceptionMessage('T::$x::set');
        $runtime->parseAndCompile($code, 'trait.php');
    }
}
