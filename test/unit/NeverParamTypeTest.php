<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6633 #7414 */
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

    public function testNeverInUnionParamCompilesAndAcceptsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x): int {
    return $x;
}
echo f(42);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_union_param.php'));
        $this->assertSame('42', ob_get_clean());
    }

    public function testNeverInUnionParamRejectsString(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x): void {}
try {
    f('bad');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_union_param_bad.php'));
        $this->assertSame(
            "TypeError: Argument must be of type int|never, string given\n",
            ob_get_clean()
        );
    }

}
