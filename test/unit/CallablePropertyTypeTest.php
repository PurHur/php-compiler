<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26516 */
final class CallablePropertyTypeTest extends TestCase
{
    public function testStandaloneCallablePropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public callable $c;
}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type callable');
        $runtime->parseAndCompile($code, 'callable_property.php');
    }

    public function testNullableCallablePropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public ?callable $c = null;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type ?callable');
        $runtime->parseAndCompile($code, 'nullable_callable_property.php');
    }

    public function testCallableUnionPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public callable|string $c;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type callable|string');
        $runtime->parseAndCompile($code, 'callable_union_property.php');
    }

    public function testPromotedCallablePropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public callable $c) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type callable');
        $runtime->parseAndCompile($code, 'callable_promoted_property.php');
    }

    public function testPromotedNullableCallablePropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public ?callable $c = null) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type ?callable');
        $runtime->parseAndCompile($code, 'nullable_callable_promoted_property.php');
    }

    public function testStaticCallablePropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public static callable $c;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Property C::$c cannot have type callable');
        $runtime->parseAndCompile($code, 'callable_static_property.php');
    }

    public function testCallableParamAndReturnStillAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(callable $c): callable { return $c; }
class C {
    public function m(callable $c): callable { return $c; }
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'callable_param_return_ok.php');
        $this->assertNotNull($block);
    }
}
