<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for ob_list_handlers / ob_get_contents / ob_get_length (#30683).
 *
 * php-src: ext/standard/output.c
 */
final class Issue30683ObExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30683_ob_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30683_ob_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ob_list_handlers ArgumentCountError: ob_list_handlers() expects exactly 0 arguments, 1 given\n"
            ."ob_get_contents ArgumentCountError: ob_get_contents() expects exactly 0 arguments, 1 given\n"
            ."ob_get_length ArgumentCountError: ob_get_length() expects exactly 0 arguments, 1 given\n"
            ."ok contents='x' length=1 handlers=array\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
