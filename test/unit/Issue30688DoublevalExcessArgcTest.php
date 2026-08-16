<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * doubleval() excess argc → ArgumentCountError cites doubleval(), not floatval() (#30688).
 *
 * php-src: ext/standard/type.c
 */
final class Issue30688DoublevalExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30688_doubleval_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30688_doubleval_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "dv_hi:ArgumentCountError:doubleval() expects exactly 1 argument, 2 given\n"
            ."dv_lo:ArgumentCountError:doubleval() expects exactly 1 argument, 0 given\n"
            ."dv_ok:3.5\n"
            ."fv_hi:ArgumentCountError:floatval() expects exactly 1 argument, 2 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString(
            'dv_hi:ArgumentCountError:floatval() expects exactly 1 argument, 2 given',
            $out
        );
    }
}
