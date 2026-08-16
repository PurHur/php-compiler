<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * fopen() excess argc ArgumentCountError wording matches Zend (#30574).
 *
 * php-src: ext/standard/file.c
 */
final class Issue30574FopenExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30574_fopen_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30574_fopen_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "excess:ArgumentCountError:fopen() expects at most 4 arguments, 5 given\n"
            ."missing:ArgumentCountError:fopen() expects at least 2 arguments, 1 given\n",
            $out
        );
        $this->assertStringNotContainsString('at least 2 arguments, 5 given', $out);
    }
}
