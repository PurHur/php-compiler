<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for error_get_last / error_clear_last (#30674).
 *
 * php-src: ext/standard/error.c
 */
final class Issue30674ErrorLastExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30674_error_last_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30674_error_last_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "error_get_last_0:ArgumentCountError:error_get_last() expects exactly 0 arguments, 1 given\n"
            ."error_get_last_1:ArgumentCountError:error_get_last() expects exactly 0 arguments, 2 given\n"
            ."error_clear_last_0:ArgumentCountError:error_clear_last() expects exactly 0 arguments, 1 given\n"
            ."error_clear_last_1:ArgumentCountError:error_clear_last() expects exactly 0 arguments, 2 given\n"
            ."error_get_last_2:OK:null\n"
            ."error_clear_last_2:OK:null\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
