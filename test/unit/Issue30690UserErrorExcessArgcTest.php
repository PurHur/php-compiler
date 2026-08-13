<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * user_error() excess argc → ArgumentCountError "at most 2" (#30690).
 *
 * php-src: Zend/zend_builtin_functions.c
 */
final class Issue30690UserErrorExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30690_user_error_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30690_user_error_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ue_hi:ArgumentCountError:user_error() expects at most 2 arguments, 3 given\n"
            ."ue_lo:ArgumentCountError:user_error() expects at least 1 argument, 0 given\n"
            ."ue_ok:true\n"
            ."te_hi:ArgumentCountError:trigger_error() expects at most 2 arguments, 3 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('expects at least 1 argument, 3 given', $out);
    }
}
