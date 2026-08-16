<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for get_included_files / get_required_files (#30654).
 *
 * php-src: ext/standard/basic_functions.c
 */
final class Issue30654IncludedFilesExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30654_included_files_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30654_included_files_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "get_included_files_0:ArgumentCountError:get_included_files() expects exactly 0 arguments, 1 given\n"
            ."get_included_files_1:ArgumentCountError:get_included_files() expects exactly 0 arguments, 2 given\n"
            ."get_included_files_2:OK:array\n"
            ."get_required_files_0:ArgumentCountError:get_required_files() expects exactly 0 arguments, 1 given\n"
            ."get_required_files_1:ArgumentCountError:get_required_files() expects exactly 0 arguments, 2 given\n"
            ."get_required_files_2:OK:array\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
