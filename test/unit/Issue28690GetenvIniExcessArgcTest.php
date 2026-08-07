<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * getenv/putenv/ini helpers/error_reporting/trigger_error excess argc → ArgumentCountError (#28690).
 *
 * php-src: ext/standard/basic_functions.c / basic_functions.stub.php
 */
final class Issue28690GetenvIniExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28690.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28690.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();

        $this->assertStringContainsString(
            'getenv:ArgumentCountError:getenv() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'putenv:ArgumentCountError:putenv() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ini_get:ArgumentCountError:ini_get() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ini_set:ArgumentCountError:ini_set() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'error_reporting:ArgumentCountError:error_reporting() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'trigger_error:ArgumentCountError:trigger_error() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'trigger_error_zero:ArgumentCountError:trigger_error() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
