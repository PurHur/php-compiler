<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for disk_*_space (#30552).
 *
 * php-src: ext/standard/filestat.c / basic_functions.stub.php
 */
final class Issue30552DiskSpaceExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_disk_space_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_disk_space_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "disk_free_space excess => ArgumentCountError: disk_free_space() expects exactly 1 argument, 2 given\n"
            ."disk_free_space missing => ArgumentCountError: disk_free_space() expects exactly 1 argument, 0 given\n"
            ."disk_total_space excess => ArgumentCountError: disk_total_space() expects exactly 1 argument, 2 given\n"
            ."disk_total_space missing => ArgumentCountError: disk_total_space() expects exactly 1 argument, 0 given\n"
            ."diskfreespace excess => ArgumentCountError: diskfreespace() expects exactly 1 argument, 2 given\n"
            ."diskfreespace missing => ArgumentCountError: diskfreespace() expects exactly 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('accepts at most one argument', $out);
    }
}
