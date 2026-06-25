<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class NamedArgsTest extends TestCase
{
    public function testUnknownNamedParameterRejects(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(int $a): int {
    return $a;
}
g(foo: 1);
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter $foo');
        $runtime->run($runtime->parseAndCompile($code, 'named_args_unknown.php'));
    }

    public function testDuplicateNamedParameterRejects(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int $a, int $b): int {
    return $a + $b;
}
f(a: 1, a: 2);
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Named parameter $a overwrites previous argument');
        $runtime->parseAndCompile($code, 'named_args_duplicate.php');
    }

    public function testResolverReordersNamedArguments(): void
    {
        $v0 = new Variable(Variable::TYPE_INTEGER);
        $v0->int(3);
        $v1 = new Variable(Variable::TYPE_INTEGER);
        $v1->int(2);
        $resolved = NamedArgs::resolve(
            [['n', 'b', $v1], ['n', 'a', $v0]],
            ['a', 'b'],
            null
        );
        $this->assertSame(3, $resolved[0]->toInt());
        $this->assertSame(2, $resolved[1]->toInt());
    }

    public function testVariadicNamedArgumentsPopulateArgsArray(): void
    {
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $resolved = NamedArgs::resolve(
            [['n', 'a', $a], ['n', 'b', $b]],
            ['args'],
            0
        );
        $this->assertCount(1, $resolved);
        $this->assertSame(Variable::TYPE_ARRAY, $resolved[0]->type);
        $packed = $resolved[0]->toArray();
        $this->assertSame(1, $packed->find('a')?->toInt());
        $this->assertSame(2, $packed->find('b')?->toInt());
    }

    public function testVariadicNamedWithLeadingPositionalParam(): void
    {
        $x = new Variable(Variable::TYPE_INTEGER);
        $x->int(1);
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(2);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(3);
        $resolved = NamedArgs::resolve(
            [['n', 'x', $x], ['n', 'a', $a], ['n', 'b', $b]],
            ['x', 'args'],
            1
        );
        $this->assertCount(2, $resolved);
        $this->assertSame(1, $resolved[0]->toInt());
        $packed = $resolved[1]->toArray();
        $this->assertSame(2, $packed->find('a')?->toInt());
        $this->assertSame(3, $packed->find('b')?->toInt());
    }

    public function testVariadicPositionalThenNamedOverflow(): void
    {
        $x = new Variable(Variable::TYPE_INTEGER);
        $x->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $resolved = NamedArgs::resolve(
            [['p', $x], ['n', 'b', $b]],
            ['x', 'args'],
            1
        );
        $this->assertCount(2, $resolved);
        $this->assertSame(1, $resolved[0]->toInt());
        $packed = $resolved[1]->toArray();
        $this->assertSame(2, $packed->find('b')?->toInt());
    }

    public function testVariadicNamedMatchingVariadicParamName(): void
    {
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $resolved = NamedArgs::resolve(
            [['n', 'a', $a], ['n', 'b', $b]],
            ['a'],
            0
        );
        $this->assertCount(1, $resolved);
        $packed = $resolved[0]->toArray();
        $this->assertSame(1, $packed->find('a')?->toInt());
        $this->assertSame(2, $packed->find('b')?->toInt());
        $this->assertNull($packed->findIndex(0));
    }

    public function testVariadicNamedMatchingParamNameDuplicateRejects(): void
    {
        $a = new Variable(Variable::TYPE_INTEGER);
        $a->int(1);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Named parameter $a overwrites previous argument');
        NamedArgs::resolve(
            [['n', 'a', $a], ['n', 'a', $b]],
            ['a'],
            0
        );
    }

    public function testNamedTrailingParamAfterVariadic(): void
    {
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $resolved = NamedArgs::resolve(
            [['n', 'b', $b]],
            ['rest', 'b'],
            0
        );
        $this->assertCount(1, $resolved);
        $this->assertSame(2, $resolved[1]->toInt());
    }

    public function testNamedTrailingParamAfterVariadicWithOverflow(): void
    {
        $extra = new Variable(Variable::TYPE_INTEGER);
        $extra->int(9);
        $b = new Variable(Variable::TYPE_INTEGER);
        $b->int(2);
        $resolved = NamedArgs::resolve(
            [['n', 'extra', $extra], ['n', 'b', $b]],
            ['rest', 'b'],
            0
        );
        $this->assertCount(2, $resolved);
        $this->assertSame(2, $resolved[1]->toInt());
        $packed = $resolved[0]->toArray();
        $this->assertSame(9, $packed->find('extra')?->toInt());
    }

    /** @covers issue #11844 — promoted ctor named args skip default slots (Zend/zend_compile.c) */
    public function testPromotedConstructorNamedArgsSkipDefaultSlot(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public int $a, public int $b = 0, public int $c = 0) {}
}
$c = new C(c: 3, a: 1);
echo ($c->a === 1 && $c->b === 0 && $c->c === 3) ? "ok\n" : "fail\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'promoted_ctor_named_skip.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
