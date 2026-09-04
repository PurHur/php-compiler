<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Lone FuncCall before ArrayDimFetch sibling call-arg must EXEC_RETURN (#36380).
 *
 * Parsedown setext uses chop(chop($Line['text'], ' '), $Line['text'][0]); without a return
 * slot both ARG_SENDs collapse onto the dim and every `- li` line becomes an h2 underline.
 *
 * php-src: Zend/zend_compile.c zend_compile_func_call / ZEND_SEND_VAL — each arg evaluates
 * into its own temporary before the call.
 */
final class FuncCallDimSiblingCallArg36380Test extends \PHPUnit\Framework\TestCase
{
    public function testShowIdThenStringOffsetKeepsBothArgs(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/call_arg_func_then_dim_36380.php'
        ));
        $out = ob_get_clean();
        $this->assertStringContainsString('a="hello" b="h"', $out);
        $this->assertStringContainsString('a="HELLO" b="e"', $out);
        $this->assertStringContainsString('a=5 b="h"', $out);
    }

    public function testNestedChopWithStringOffsetMatchesZendSetextGuard(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/setext_chop_line_array_36380.php'
        ));
        $out = ob_get_clean();
        $this->assertStringContainsString('"- li" => no', $out);
        $this->assertStringContainsString('"-------- | --------" => no', $out);
        $this->assertStringContainsString('"-------|" => no', $out);
        $this->assertStringContainsString('"---" => setext:h2', $out);
    }
}
