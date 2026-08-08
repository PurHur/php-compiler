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
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = '<?php echo 0x1.8p+1;';
            $desugared = HexFloatLiteralDesugar::desugar($code);
            $this->assertStringContainsString('echo 3', $desugared);
            $this->assertStringNotContainsString('0x1.8p+1', $desugared);
        } finally {
            $this->restoreProfile($prev);
        }
    }

    public function testDesugarLeavesHexIntegersUntouched(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = '<?php var_dump(0xFF, 0xAB, 0xDEADBEEF, 0x1A);';
            $desugared = HexFloatLiteralDesugar::desugar($code);
            $this->assertSame($code, $desugared);
        } finally {
            $this->restoreProfile($prev);
        }
    }

    public function testDesugarRejectsMalformedHexFloatSuffix(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->expectException(\PhpParser\Error::class);
            $this->expectExceptionMessage('Invalid numeric literal');
            HexFloatLiteralDesugar::desugar('<?php echo 0x1.8q+1;');
        } finally {
            $this->restoreProfile($prev);
        }
    }

    /** Issue #29061: PROFILE=8.2 must not desugar hex floats (Zend 8.2 parse-errors). */
    public function testDesugarNoOpUnderProfile82(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $code = '<?php echo 0x1.8p+1;';
            $this->assertSame($code, HexFloatLiteralDesugar::desugar($code));
        } finally {
            $this->restoreProfile($prev);
        }
    }

    /** @param string|false $prev */
    private function restoreProfile($prev): void
    {
        if (false === $prev || '' === $prev) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$prev);
        }
    }
}
