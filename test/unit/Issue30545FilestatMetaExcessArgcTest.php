<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for filesize/filetype/file*time (#30545).
 *
 * php-src: ext/standard/filestat.c / file.stub.php
 */
final class Issue30545FilestatMetaExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_filestat_meta_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_filestat_meta_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "filesize() expects exactly 1 argument, 2 given\n"
            ."filetype() expects exactly 1 argument, 2 given\n"
            ."filemtime() expects exactly 1 argument, 2 given\n"
            ."filectime() expects exactly 1 argument, 2 given\n"
            ."fileatime() expects exactly 1 argument, 2 given\n"
            ."filesize() expects exactly 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
