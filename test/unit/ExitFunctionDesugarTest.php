<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\ExitFunctionDesugar;
use PHPUnit\Framework\TestCase;

/** @covers issue #6975 */
final class ExitFunctionDesugarTest extends TestCase
{
    public function testTwoArgExitBecomesMarker(): void
    {
        $out = ExitFunctionDesugar::desugar('<?php exit(1, "bye");');
        $this->assertStringContainsString('__phpcExitCall(1, "bye")', $out);
    }

    public function testTwoArgDieBecomesMarker(): void
    {
        $out = ExitFunctionDesugar::desugar('<?php die(0, "ok");');
        $this->assertStringContainsString('__phpcDieCall(0, "ok")', $out);
    }

    public function testNamedArgExitBecomesMarker(): void
    {
        $out = ExitFunctionDesugar::desugar('<?php exit(status: 0);');
        $this->assertStringContainsString('__phpcExitCall(status: 0)', $out);
    }

    public function testFirstClassCallableExitBecomesMarker(): void
    {
        $out = ExitFunctionDesugar::desugar('<?php $fn = exit(...);');
        $this->assertStringContainsString('__phpcExitCall(...)', $out);
    }

    public function testBareExitUnchanged(): void
    {
        $code = '<?php exit; exit 1;';
        $this->assertSame($code, ExitFunctionDesugar::desugar($code));
    }
}
