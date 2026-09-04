<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Lone ?: as a call argument must wire the ternary merge phi (#36380).
 *
 * php-src: Zend/zend_compile.c — compile_ternary_op result feeds ZEND_SEND_VAL.
 */
final class TernaryCallArgPhi36380Test extends \PHPUnit\Framework\TestCase
{
    public function testInlineTernaryCallArgMatchesAssigned(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/ternary_callarg_36380.php'
        ));
        $out = ob_get_clean();
        $this->assertStringContainsString('inline_arg=3', $out);
        $this->assertStringContainsString('lit_false_arg=3', $out);
        $this->assertStringContainsString('lit_true_arg=0', $out);
        $this->assertStringContainsString('hs_inline=a&quot;b', $out);
        $this->assertStringContainsString('str_pad=x----', $out);
        $this->assertStringContainsString('max=5', $out);
    }

    public function testParsedownEscapeQuotesUnderVm(): void
    {
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompileFile(
            __DIR__ . '/../repro/xss_escape2.php'
        ));
        $out = ob_get_clean();
        $this->assertStringContainsString('pd_exact=https://www.example.com&quot;', $out);
        $this->assertStringContainsString('tern=https://www.example.com&quot;', $out);
    }
}
