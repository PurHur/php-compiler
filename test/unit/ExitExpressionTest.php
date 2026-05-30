<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** @covers issue #3539 */
final class ExitExpressionTest extends TestCase
{
    public function testExitExpressionTerminatesBeforeDeadFollowUp(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$x = (exit);
echo "never\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'exit_expr_dead.php');
        $this->assertNotNull($block);
        $this->assertSame(OpCode::TYPE_EXIT, $block->opCodes[0]->type);
        ob_start();
        try {
            $runtime->run($block);
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }

    public function testExitExpressionWithMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$x = (exit('gone'));
echo "never\n";
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'exit_expr_msg.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('gone', ob_get_clean());
    }

    public function testExitExpressionInVoidFunction(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {
    $x = (exit);
    echo "after\n";
}
f();
echo "done\n";
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'exit_void_fn.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }

    public function testExitExpressionClosureDefersUntilInvoke(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$fn = function () {
    return (exit);
};
echo "ok\n";
$fn();
echo "after\n";
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'exit_closure.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame("ok\n", ob_get_clean());
    }
}
