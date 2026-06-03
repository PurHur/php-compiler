<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class NamedArgsUnpackAfterNamedCompileTest extends TestCase
{
    public function testUnpackAfterNamedIsCompileError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function demo(int $a, int $b, int $c): int {
    return $a + $b + $c;
}
$rest = [2, 3];
demo(a: 1, ...$rest);
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use argument unpacking after named arguments');
        $runtime->parseAndCompile($code, 'named_unpack_after_named.php');
    }

    public function testResolverFillsUnfilledParamsAfterNamed(): void
    {
        $vA = new Variable(Variable::TYPE_INTEGER);
        $vA->int(1);
        $vB = new Variable(Variable::TYPE_INTEGER);
        $vB->int(2);
        $vC = new Variable(Variable::TYPE_INTEGER);
        $vC->int(3);
        $resolved = NamedArgs::resolve(
            [['n', 'a', $vA], ['p', $vB], ['p', $vC]],
            ['a', 'b', 'c'],
            null
        );
        $this->assertSame(1, $resolved[0]->toInt());
        $this->assertSame(2, $resolved[1]->toInt());
        $this->assertSame(3, $resolved[2]->toInt());
    }
}
