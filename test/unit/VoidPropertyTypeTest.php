<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26518 */
final class VoidPropertyTypeTest extends TestCase
{
    public function testStandaloneVoidPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public void $p;
}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type void');
        $runtime->parseAndCompile($code, 'void_property.php');
    }

    public function testStaticVoidPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static void $p;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type void');
        $runtime->parseAndCompile($code, 'void_static_property.php');
    }

    public function testPromotedVoidPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public void $p) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$p cannot have type void');
        $runtime->parseAndCompile($code, 'void_promoted_property.php');
    }

    public function testVoidUnionPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public void|int $p;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'void_union_property.php');
    }

    public function testNullableVoidPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public ?void $p;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'nullable_void_property.php');
    }

    public function testVoidReturnTypeStillAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {}
class C {
    public function m(): void {}
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'void_return_ok.php');
        $this->assertNotNull($block);
    }
}
