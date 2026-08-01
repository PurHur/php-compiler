<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\EnumParentCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5410 */
final class EnumParentCompileCheckTest extends TestCase
{
    public function testParentInEnumMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public function f() {
        parent::x;
    }
}
PHP,
            'enum_parent.php'
        );
    }

    public function testParentClassInEnumMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public function f(): void {
        parent::class;
    }
}
PHP,
            'enum_parent_class.php'
        );
    }

    public function testSubclassParentStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class B {}
class C extends B {
    public function f() {
        parent::class;
    }
}
PHP,
            'class_parent.php'
        );
        $this->assertNotNull($block);
    }

    /** @covers issue #26540 */
    public function testParentUnionTypeInParentlessClassFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class A {
    function f(): parent|int {
        return 1;
    }
}
PHP,
            'parent_union_type.php'
        );
    }

    /** @covers issue #26540 */
    public function testParentReturnTypeOnEnumFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
enum E {
    case A;
    public function f(): parent {
        return $this;
    }
}
PHP,
            'enum_parent_type.php'
        );
    }

    /** @covers issue #26540 */
    public function testParentUnionTypeWithParentStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Base {}
class A extends Base {
    function f(): parent|int {
        return 1;
    }
}
PHP,
            'parent_union_ok.php'
        );
        $this->assertNotNull($block);
    }

    /** @covers issue #26540 */
    public function testTraitParentTypeStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T {
    function f(): parent {
        return $this;
    }
}
PHP,
            'trait_parent_type.php'
        );
        $this->assertNotNull($block);
    }

    /** @covers issue #7381 */
    public function testParentInParentlessClassMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function f(): void {
        parent::g();
    }
}
PHP,
            'parent_no_parent.php'
        );
    }

    /** @covers issue #7381 */
    public function testParentInParentlessClassConstFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(EnumParentCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = parent::Y;
}
PHP,
            'parent_no_parent_const.php'
        );
    }
}
