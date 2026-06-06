<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6633 */
final class NeverParamTypeTest extends TestCase
{
    public function testStandaloneNeverParamCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function acceptsNever(never $value): void {}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_param.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testNeverParamCallSiteTypeError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(never $x) {
    echo "hi";
}
f(1);
PHP;
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must be of type never');
        $runtime->run($runtime->parseAndCompile($code, 'never_param_call.php'));
    }

    public function testNeverInUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_union_param.php');
    }

}
