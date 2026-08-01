<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26554 */
final class NullableMixedTypeTest extends TestCase
{
    public function testNullableMixedParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(?mixed $x) {
    return $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type mixed cannot be marked as nullable since mixed already includes null');
        $runtime->parseAndCompile($code, 'nullable_mixed_param.php');
    }

    public function testNullableMixedReturnRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): ?mixed {
    return null;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type mixed cannot be marked as nullable since mixed already includes null');
        $runtime->parseAndCompile($code, 'nullable_mixed_return.php');
    }

    public function testNullableMixedPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public ?mixed $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type mixed cannot be marked as nullable since mixed already includes null');
        $runtime->parseAndCompile($code, 'nullable_mixed_property.php');
    }

    public function testMixedNullUnionParamRejectedAsStandalone(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(mixed|null $x): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type mixed can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'mixed_null_union_param.php');
    }

    public function testStandaloneMixedParamStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(mixed $x) {
    echo "ok\n";
}
f(1);
PHP;
        ob_start();
        $block = $runtime->parseAndCompile($code, 'standalone_mixed_param.php');
        $runtime->run($block, false);
        $out = ob_get_clean();
        $this->assertSame("ok\n", $out);
    }
}
