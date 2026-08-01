<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26556 */
final class DuplicateUnionMemberCompileCheckTest extends TestCase
{
    public function testDuplicateIntUnionParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|string|int $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type int is redundant');
        $runtime->parseAndCompile($code, 'union_dup_int_param.php');
    }

    public function testDuplicateFalseUnionParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(false|null|false $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type false is redundant');
        $runtime->parseAndCompile($code, 'union_dup_false_param.php');
    }

    public function testDuplicateUnionReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): int|string|int { return 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type int is redundant');
        $runtime->parseAndCompile($code, 'union_dup_return.php');
    }

    public function testDuplicateUnionPropertyIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C { public int|string|int $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type int is redundant');
        $runtime->parseAndCompile($code, 'union_dup_prop.php');
    }

    public function testDuplicateClassUnionUsesSecondSpelling(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Foo {}
class Bar {}
function f(Foo|Bar|Foo $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type Foo is redundant');
        $runtime->parseAndCompile($code, 'union_dup_class.php');
    }

    public function testDuplicateAcrossIntersectionArmIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(int|(A&B)|int $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Duplicate type int is redundant');
        $runtime->parseAndCompile($code, 'union_dup_across_dnf.php');
    }
}
