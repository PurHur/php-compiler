<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_headers() ArgumentCountError wording matches Zend (#31192).
 *
 * php-src: ext/standard/head.c
 */
final class Issue31192GetHeadersExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31192_get_headers_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31192_get_headers_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "excess:ArgumentCountError:get_headers() expects at most 3 arguments, 4 given\n"
            ."missing:ArgumentCountError:get_headers() expects at least 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('at least 1 argument, 4 given', $out);
    }
}
