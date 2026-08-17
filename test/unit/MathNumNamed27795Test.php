<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** deg2rad-family Reflection $num + named args (#27795, basic_functions.stub.php). */
final class MathNumNamed27795Test extends TestCase
{
    public function testReflectionAndNamedNum(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27795_math_num_named.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27795.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "deg2rad param=num type=float ret=float\n"
            ."rad2deg param=num type=float ret=float\n"
            ."expm1 param=num type=float ret=float\n"
            ."log1p param=num type=float ret=float\n"
            ."asinh param=num type=float ret=float\n"
            ."acosh param=num type=float ret=float\n"
            ."atanh param=num type=float ret=float\n"
            ."deg2rad=180\n"
            ."asinh=0\n"
            ."legacy number ERR=Error: Unknown named parameter \$number\n",
            ob_get_clean()
        );
    }
}
