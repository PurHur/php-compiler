<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stream_context_set_option string-form incomplete args → ValueError (#30645).
 *
 * php-src: ext/standard/streamsfuncs.c PHP_FUNCTION(stream_context_set_option)
 */
final class Issue30645StreamContextSetOptionIncompleteTest extends TestCase
{
    public function testVmIncompleteStringFormThrowsValueError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_stream_context_set_option_incomplete.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30645.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ValueError: stream_context_set_option(): Argument #3 ($option_name) cannot be null when argument #2 ($wrapper_or_options) is a string',
            $out
        );
        $this->assertStringContainsString(
            'ValueError: stream_context_set_option(): Argument #4 ($value) must be provided when argument #2 ($wrapper_or_options) is a string',
            $out
        );
        $this->assertStringContainsString("true\ntrue\n", $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
        $this->assertStringNotContainsString('missing wrapper/option/value', $out);
    }
}
