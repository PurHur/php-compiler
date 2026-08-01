<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26606 */
final class RedundantDnfArmCompileCheckTest extends TestCase
{
    public function testExactDuplicateDnfArmsParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f((A&B)|(A&B) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type A&B is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_exact_param.php');
    }

    public function testCommutativeDnfArmsParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f((A&B)|(B&A) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type B&A is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_commute_param.php');
    }

    public function testCommutativeDnfArmsReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(): (A&B)|(B&A) { throw new Exception(); }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type B&A is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_commute_return.php');
    }

    public function testCommutativeDnfArmsPropertyIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
class C { public (A&B)|(B&A) $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type B&A is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_commute_prop.php');
    }

    public function testCaseInsensitiveSpellingUsesSecondArm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f((A&B)|(b&a) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type b&a is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_case.php');
    }

    public function testRedundantArmAcrossOtherUnionMember(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((A&B)|C|(B&A) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type B&A is redundant with type A&B');
        $runtime->parseAndCompile($code, 'dnf_redundant_with_other.php');
    }

    public function testDistinctDnfArmsAreAccepted(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
interface C {}
function f((A&B)|(A&C) $x) {}
echo "ok\n";
PHP;
        $runtime->parseAndCompile($code, 'dnf_distinct_arms.php');
        $this->assertTrue(true);
    }
}
