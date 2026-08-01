<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26607 */
final class RedundantDnfArmSubsetCompileCheckTest extends TestCase
{
    public function testIntersectionSubsetParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((A&B)|(A&B&C) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A&B&C is redundant as it is more restrictive than type A&B'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_param.php');
    }

    public function testIntersectionSubsetReverseArmOrder(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((A&B&C)|(A&B) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A&B&C is redundant as it is more restrictive than type A&B'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_param_rev.php');
    }

    public function testIntersectionSubsetPreservesMemberSourceOrder(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((B&A)|(C&B&A) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type C&B&A is redundant as it is more restrictive than type B&A'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_member_order.php');
    }

    public function testSingleClassVsIntersectionIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(A|(A&B) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A&B is redundant as it is more restrictive than type A'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_single.php');
    }

    public function testIntersectionSubsetReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f(): (A&B)|(A&B&C) { throw new Exception(); }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A&B&C is redundant as it is more restrictive than type A&B'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_return.php');
    }

    public function testIntersectionSubsetPropertyIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
class T { public (A&B)|(A&B&C) $p; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A&B&C is redundant as it is more restrictive than type A&B'
        );
        $runtime->parseAndCompile($code, 'dnf_subset_prop.php');
    }

    public function testDisjointIntersectionArmsRemainValid(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((A&B)|(A&C) $x) { return $x; }
PHP;
        $runtime->parseAndCompile($code, 'dnf_disjoint_ok.php');
        $this->assertTrue(true);
    }

    public function testExactDuplicateArmsRejectedByEqualityCheck(): void
    {
        // Equality / commutative redundancy is sibling #26606 — now a compile fatal.
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f((A&B)|(B&A) $x) { return $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type B&A is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_eq_deferred.php');
    }
}
