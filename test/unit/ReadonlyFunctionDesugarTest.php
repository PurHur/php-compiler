<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\ReadonlyFunctionDesugar;
use PHPUnit\Framework\TestCase;

/** @covers issue #7428 */
final class ReadonlyFunctionDesugarTest extends TestCase
{
    public function testStripsReadonlyBeforeTopLevelFunction(): void
    {
        $code = <<<'PHP'
<?php
readonly function f(): void {}
PHP;
        [$out, $lines] = ReadonlyFunctionDesugar::desugar($code);
        $this->assertStringNotContainsString('readonly function', $out);
        $this->assertSame([2], $lines);
    }

    public function testLeavesReadonlyClassUntouched(): void
    {
        $code = <<<'PHP'
<?php
readonly class C {
    function m() {}
}
PHP;
        [$out, $lines] = ReadonlyFunctionDesugar::desugar($code);
        $this->assertStringContainsString('readonly class', $out);
        $this->assertSame([], $lines);
    }

    public function testStripsReadonlyClosureExpression(): void
    {
        $code = <<<'PHP'
<?php
$f = readonly function () {};
PHP;
        [$out, $lines] = ReadonlyFunctionDesugar::desugar($code);
        $this->assertStringNotContainsString('readonly function', $out);
        $this->assertSame([2], $lines);
    }
}
