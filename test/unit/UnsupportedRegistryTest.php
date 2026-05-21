<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\UnsupportedRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @see https://github.com/PurHur/php-compiler/issues/258
 * @see https://github.com/PurHur/php-compiler/issues/297
 */
final class UnsupportedRegistryTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function kindToIssueProvider(): array
    {
        return [
            'assign coalesce' => ['Expr_AssignOp_Coalesce', 99],
            'break' => ['Stmt_Break', 115],
            'continue' => ['Stmt_Continue', 115],
            'match' => ['Expr_Match', 143],
            'yield' => ['Expr_Yield', 167],
            'yield from' => ['Expr_YieldFrom', 167],
            'closure' => ['Expr_Closure', 72],
            'arrow function' => ['Expr_ArrowFunction', 142],
            'pre inc' => ['Expr_PreInc', 137],
            'post inc' => ['Expr_PostInc', 137],
            'pre dec' => ['Expr_PreDec', 137],
            'post dec' => ['Expr_PostDec', 137],
            'method call' => ['Expr_MethodCall', 58],
            'class method' => ['Stmt_ClassMethod', 58],
        ];
    }

    /**
     * @dataProvider kindToIssueProvider
     */
    public function testTrackingIssueForKind(string $kind, int $issue): void
    {
        $this->assertSame($issue, UnsupportedRegistry::trackingIssueForKind($kind));
    }

    public function testUnknownKindReturnsNull(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_FooBar'));
    }

    public function testKindFromMessageExtractsClassMethod(): void
    {
        $kind = Issue::kindFromMessage(
            'Unsupported class body element: PHPCfg\Op\Stmt\ClassMethod'
        );
        $this->assertSame('Stmt_ClassMethod', $kind);
        $this->assertSame(58, UnsupportedRegistry::trackingIssueForKind($kind));
    }

    public function testKindFromMessageExtractsMethodCall(): void
    {
        $kind = Issue::kindFromMessage('Unsupported expression: Expr_MethodCall');
        $this->assertSame('Expr_MethodCall', $kind);
        $this->assertSame(58, UnsupportedRegistry::trackingIssueForKind($kind));
    }
}
