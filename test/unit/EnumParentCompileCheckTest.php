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
}
