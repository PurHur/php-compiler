<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\PipeOperatorDesugar;
use PHPUnit\Framework\TestCase;

/** Pipe operator reference profile gate (#12424). */
final class PipeOperatorReferenceProfileTest extends TestCase
{
    public function testSupportsPipeOperatorFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPipeOperator());
    }

    public function testDesugarNoOpWhenPipeDisabled(): void
    {
        if (CompilerVersion::supportsPipeOperator()) {
            $this->markTestSkipped('pipe operator enabled on PHP 8.4.0+ target');
        }
        $src = '<?php $x = 5 |> fn ($v) => $v * 2;';
        $this->assertSame($src, PipeOperatorDesugar::desugar($src));
    }
}
