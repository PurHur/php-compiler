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
        $this->expectException(\LogicException::class);
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
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be passed only once');
        $runtime->run($runtime->parseAndCompile($code, 'named_args_duplicate.php'));
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
}
