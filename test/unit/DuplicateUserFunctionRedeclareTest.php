<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #31109
 *
 * php-src: Zend/zend_compile.c — Cannot redeclare %s() (previously declared in %s:%d)
 */
final class DuplicateUserFunctionRedeclareTest extends TestCase
{
    public function testSameFileDuplicateIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('Cannot redeclare f() (previously declared in');
        $runtime->parseAndCompile(<<<'PHP'
<?php
function f() {}
function f() {}
PHP,
            'dup_func.php'
        );
    }

    public function testCaseInsensitiveDuplicateUsesSecondSpelling(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(<<<'PHP'
<?php
function f() {}
function F() {}
PHP,
                'dup_func_case.php'
            );
            $this->fail('Expected CompileFatal');
        } catch (CompileFatal $e) {
            $this->assertStringContainsString('Cannot redeclare F()', $e->getMessage());
            $this->assertStringContainsString('previously declared in', $e->getMessage());
        }
    }

    public function testFalseBranchDuplicateStillRuns(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function f() {}
if (false) {
    function f() {}
}
echo "ok\n";
PHP,
            'dup_func_false_branch.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testRuntimeDuplicateIsUncatchableScriptExit(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
try {
    if (true) {
        function f() {}
        function f() {}
    }
} catch (Throwable $e) {
    echo "CAUGHT\n";
}
echo "REACHED\n";
PHP,
            'dup_func_runtime.php'
        );
        $this->assertNotNull($block);

        ob_start();
        try {
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected ScriptExit for runtime function redeclare');
        } catch (CompileFatal $e) {
            ob_end_clean();
            $this->fail('Runtime redeclare must not surface as catchable CompileFatal: '.$e->getMessage());
        } catch (ScriptExit $e) {
            $out = ob_get_clean();
            $this->assertSame(255, $e->status);
            $this->assertStringNotContainsString('CAUGHT', (string) $out);
            $this->assertStringNotContainsString('REACHED', (string) $out);
        }
    }

    public function testInternalFunctionRedeclareOmitsPreviouslyDeclared(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
if (true) {
    function strlen($s) {}
}
echo "REACHED\n";
PHP,
            'dup_strlen.php'
        );
        $this->assertNotNull($block);

        ob_start();
        try {
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected ScriptExit for strlen redeclare');
        } catch (ScriptExit $e) {
            $out = ob_get_clean();
            $this->assertSame(255, $e->status);
            $this->assertStringNotContainsString('REACHED', (string) $out);
        }
    }
}
