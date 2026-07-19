<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/** Unparenthesized nested ternary rejector (#20737). */
final class NestedTernaryRejectorTest extends TestCase
{
    public function testRejectsUnparenthesizedTernaryChain(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NestedTernaryRejector::MSG_TERNARY_TERNARY);

        NestedTernaryRejector::reject(
            '<?php echo true ? "a" : false ? "b" : "c";',
            'nested.php'
        );
    }

    public function testRejectsTernaryThenElvis(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NestedTernaryRejector::MSG_TERNARY_ELVIS);

        NestedTernaryRejector::reject(
            '<?php echo true ? "a" : false ?: "c";',
            'nested.php'
        );
    }

    public function testRejectsElvisThenTernary(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(NestedTernaryRejector::MSG_ELVIS_TERNARY);

        NestedTernaryRejector::reject(
            '<?php echo false ?: true ? "a" : "b";',
            'nested.php'
        );
    }

    public function testAllowsParenthesizedLeftNesting(): void
    {
        $code = '<?php echo (true ? "a" : false) ? "b" : "c";';
        self::assertSame($code, NestedTernaryRejector::reject($code, 'ok.php'));
    }

    public function testAllowsParenthesizedRightNesting(): void
    {
        $code = '<?php echo true ? "a" : (false ? "b" : "c");';
        self::assertSame($code, NestedTernaryRejector::reject($code, 'ok.php'));
    }

    public function testAllowsMiddleArmNesting(): void
    {
        $code = '<?php echo true ? false ? "b" : "c" : "a";';
        self::assertSame($code, NestedTernaryRejector::reject($code, 'ok.php'));
    }

    public function testAllowsPureElvisChaining(): void
    {
        $code = '<?php echo 0 ?: 0 ?: "x";';
        self::assertSame($code, NestedTernaryRejector::reject($code, 'ok.php'));
    }
}
