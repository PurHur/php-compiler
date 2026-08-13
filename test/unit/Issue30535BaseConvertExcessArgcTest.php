<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for dechex/decoct/decbin/octdec (#30535).
 *
 * php-src: ext/standard/math.c / basic_functions.stub.php
 */
final class Issue30535BaseConvertExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30535_base_convert_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30535_base_convert_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "dechex() expects exactly 1 argument, 2 given\n"
            ."decoct() expects exactly 1 argument, 2 given\n"
            ."decbin() expects exactly 1 argument, 2 given\n"
            ."octdec() expects exactly 1 argument, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
