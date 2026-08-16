<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * get_included_files/get_required_files excess argc ACE wording (#30654).
 *
 * php-src: ext/standard/basic_functions.c
 */
final class Issue30654GetIncludedFilesExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30654_get_included_files_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30654_get_included_files_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: get_included_files() expects exactly 0 arguments, 1 given\n"
            ."ArgumentCountError: get_required_files() expects exactly 0 arguments, 1 given\n",
            $out
        );
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
