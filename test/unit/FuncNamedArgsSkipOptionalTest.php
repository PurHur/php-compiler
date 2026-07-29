<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #24948 */
final class FuncNamedArgsSkipOptionalTest extends TestCase
{
    public function testFuncArgsDensifySkippedLeadingOptional(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f($a = 1, $b = 2) {
    echo func_num_args(), json_encode(func_get_args()), func_get_arg(1);
}
f(b: 9);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_24948_func_named_skip.php'));
        $this->assertSame('2[1,9]9', ob_get_clean());
    }

    public function testCallUserFuncNamedSkipMatchesArgc(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function h($a = 1, $b = 2) {
    echo func_num_args();
}
call_user_func('h', b: 9);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_24948_cuf_named_skip.php'));
        $this->assertSame('2', ob_get_clean());
    }

    public function testDebugBacktraceArgsDensifySkippedOptional(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f($a = 1, $b = 2) {
    echo json_encode(debug_backtrace()[0]['args']);
}
f(b: 9);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_24948_bt_named_skip.php'));
        $this->assertSame('[1,9]', ob_get_clean());
    }
}
