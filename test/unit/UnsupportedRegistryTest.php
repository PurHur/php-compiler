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

    public function testMatchNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_Match'));
    }

    public function testCoalesceAssignNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_AssignOp_Coalesce'));
    }

    public function testThrowExpressionNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_Throw'));
    }

    public function testPrePostIncrementKindsNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PreInc'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PostInc'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PreDec'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_PostDec'));
    }

    public function testClosureAndArrowNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_Closure'));
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_ArrowFunction'));
    }

    public function testYieldFromNoLongerTrackedAsUnsupported(): void
    {
        $this->assertNull(UnsupportedRegistry::trackingIssueForKind('Expr_YieldFrom'));
    }

    public function testTryCatchStillTrackedAsUnsupported(): void
    {
        $this->assertSame(57, UnsupportedRegistry::trackingIssueForKind('Stmt_TryCatch'));
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
