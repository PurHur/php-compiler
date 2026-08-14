<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * gettimeofday() excess argc → Zend at-most ArgumentCountError (#30682).
 *
 * php-src: ext/standard/microtime.c PHP_FUNCTION(gettimeofday)
 */
final class Issue30682GettimeofdayExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsAtMostArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30682_gettimeofday_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30682_gettimeofday_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "hi:ArgumentCountError:gettimeofday() expects at most 1 argument, 2 given\n"
            ."hi3:ArgumentCountError:gettimeofday() expects at most 1 argument, 3 given\n"
            ."ok0:1\n"
            ."ok1:1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('accepts at most one argument', $out);
    }
}
