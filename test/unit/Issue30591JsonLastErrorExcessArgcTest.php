<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for json_last_error / json_last_error_msg (#30591).
 *
 * php-src: ext/json/json.c / json.stub.php
 */
final class Issue30591JsonLastErrorExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30591_json_last_error_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30591_json_last_error_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "json_last_error_0:ArgumentCountError:json_last_error() expects exactly 0 arguments, 1 given\n"
            ."json_last_error_1:ArgumentCountError:json_last_error() expects exactly 0 arguments, 2 given\n"
            ."json_last_error_2:OK:0\n"
            ."json_last_error_msg_0:ArgumentCountError:json_last_error_msg() expects exactly 0 arguments, 1 given\n"
            ."json_last_error_msg_1:ArgumentCountError:json_last_error_msg() expects exactly 0 arguments, 2 given\n"
            ."json_last_error_msg_2:OK:'No error'\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
