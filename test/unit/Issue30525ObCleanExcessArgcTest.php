<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for ob_clean() (#30525).
 *
 * php-src: ext/standard/output.c / basic_functions.stub.php
 */
final class Issue30525ObCleanExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30525_ob_clean_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30525_ob_clean_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ob_clean() expects exactly 0 arguments, 1 given\n"
            ."ob_clean() expects exactly 0 arguments, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
