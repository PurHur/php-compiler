<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for unary math + pi() (#30534).
 *
 * php-src: ext/standard/math.c / basic_functions.stub.php
 */
final class Issue30534MathUnaryExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30534_math_unary_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30534_math_unary_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "pi() expects exactly 0 arguments, 1 given\n"
            ."sqrt() expects exactly 1 argument, 2 given\n"
            ."sin() expects exactly 1 argument, 2 given\n"
            ."asinh() expects exactly 1 argument, 2 given\n"
            ."deg2rad() expects exactly 1 argument, 2 given\n"
            ."log10() expects exactly 1 argument, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
