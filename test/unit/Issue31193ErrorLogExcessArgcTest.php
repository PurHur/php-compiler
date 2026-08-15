<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * error_log() ArgumentCountError wording matches Zend (#31193).
 *
 * php-src: ext/standard/basic_functions.c
 */
final class Issue31193ErrorLogExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31193_error_log_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31193_error_log_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "excess:ArgumentCountError:error_log() expects at most 4 arguments, 5 given\n"
            ."missing:ArgumentCountError:error_log() expects at least 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('between 1 and 4', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
