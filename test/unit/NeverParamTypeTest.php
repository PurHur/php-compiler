<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3506 */
final class NeverParamTypeTest extends TestCase
{
    public function testNeverParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(never $x) {
    echo "hi";
}
f(1);
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param.php');
    }

    public function testNeverReturnTypeStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function stop(): never {
    exit('gone');
}
stop();
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'never_return_guard.php'));
            $this->fail('Expected ScriptExit');
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('gone', ob_get_clean());
    }
}
