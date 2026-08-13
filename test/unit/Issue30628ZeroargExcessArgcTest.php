<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for preg_last_error / preg_last_error_msg / zend_version (#30628).
 *
 * php-src: ext/pcre/php_pcre.c + Zend/zend.c
 */
final class Issue30628ZeroargExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30628_zeroarg_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30628_zeroarg_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "preg_last_error_0:ArgumentCountError:preg_last_error() expects exactly 0 arguments, 1 given\n"
            ."preg_last_error_1:ArgumentCountError:preg_last_error() expects exactly 0 arguments, 2 given\n"
            ."preg_last_error_2:OK:0\n"
            ."preg_last_error_msg_0:ArgumentCountError:preg_last_error_msg() expects exactly 0 arguments, 1 given\n"
            ."preg_last_error_msg_1:ArgumentCountError:preg_last_error_msg() expects exactly 0 arguments, 2 given\n"
            ."preg_last_error_msg_2:OK:'No error'\n"
            ."zend_version_0:ArgumentCountError:zend_version() expects exactly 0 arguments, 1 given\n"
            ."zend_version_1:ArgumentCountError:zend_version() expects exactly 0 arguments, 2 given\n"
            ."zend_version_2:OK:nonempty_string\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
