<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\VoidCastDesugar;
use PHPUnit\Framework\TestCase;

/** @covers issue #7346 #23037 */
final class VoidCastDesugarTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.5');
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
    }

    public function testBareCallBecomesMarker(): void
    {
        $out = VoidCastDesugar::desugar('<?php (void) f();');
        $this->assertStringContainsString('__phpcVoidCast(f())', $out);
    }

    public function testParenthesizedOperandBecomesMarker(): void
    {
        $out = VoidCastDesugar::desugar('<?php (void) ($a + 1);');
        $this->assertStringContainsString('__phpcVoidCast(($a + 1))', $out);
    }

    public function testReturnTypeVoidUnchanged(): void
    {
        $code = '<?php function f(): void {}';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }

    public function testProfile82LeavesVoidCastUntouched(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.2');
        $code = '<?php (void) f();';
        $this->assertSame($code, VoidCastDesugar::desugar($code));
    }
}
