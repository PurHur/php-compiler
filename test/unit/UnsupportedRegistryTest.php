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
            'yield from' => ['Expr_YieldFrom', 167],
            'closure' => ['Expr_Closure', 72],
            'arrow function' => ['Expr_ArrowFunction', 142],
        ];
    }

    public function testMatchNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_Match'));
    }

    public function testCoalesceAssignNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_AssignOp_Coalesce'));
    }

    public function testPrePostIncrementKindsNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PreInc'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PostInc'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PreDec'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PostDec'));
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

    public function testIssueUrl(): void
    {
        $this->assertSame(
            'https://github.com/PurHur/php-compiler/issues/99',
            UnsupportedRegistry::issueUrl(99)
        );
    }

    public function testClassMethodAndMethodCallNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Stmt_ClassMethod'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_MethodCall'));
    }
}
