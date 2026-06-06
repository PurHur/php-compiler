<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\HexFloatLiteralDesugar;
use PHPCompiler\HexFloat;
use PHPUnit\Framework\TestCase;

final class HexFloatTest extends TestCase
{
    public function testParseExamples(): void
    {
        $this->assertSame(3.0, HexFloat::parse('0x1.8p+1'));
        $this->assertSame(2.734375, HexFloat::parse('0xA.Fp-2'));
        $this->assertSame(2.0, HexFloat::parse('0x1p+1'));
    }

    public function testDesugarRewritesLiteral(): void
    {
        $code = '<?php echo 0x1.8p+1;';
        $desugared = HexFloatLiteralDesugar::desugar($code);
        $this->assertStringContainsString('echo 3', $desugared);
        $this->assertStringNotContainsString('0x1.8p+1', $desugared);
    }

    public function testDesugarLeavesHexIntegersUntouched(): void
    {
        $code = '<?php var_dump(0xFF, 0xAB, 0xDEADBEEF, 0x1A);';
        $desugared = HexFloatLiteralDesugar::desugar($code);
        $this->assertSame($code, $desugared);
    }

    public function testDesugarRejectsMalformedHexFloatSuffix(): void
    {
        $this->expectException(\PhpParser\Error::class);
        $this->expectExceptionMessage('Invalid numeric literal');
        HexFloatLiteralDesugar::desugar('<?php echo 0x1.8q+1;');
    }
}
