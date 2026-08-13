<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for filestat ownership/meta/fnmatch (#30554).
 *
 * php-src: ext/standard/filestat.c / fnmatch.c / basic_functions.stub.php
 */
final class Issue30554FilestatBatchExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_filestat_batch_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_filestat_batch_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'umask(0, "x") => ArgumentCountError: umask() expects at most 1 argument, 2 given'."\n"
            .'chown("/tmp", "root", "x") => ArgumentCountError: chown() expects exactly 2 arguments, 3 given'."\n"
            .'chgrp("/tmp", "root", "x") => ArgumentCountError: chgrp() expects exactly 2 arguments, 3 given'."\n"
            .'clearstatcache(true, "/tmp", "x") => ArgumentCountError: clearstatcache() expects at most 2 arguments, 3 given'."\n"
            .'stat("/tmp", "x") => ArgumentCountError: stat() expects exactly 1 argument, 2 given'."\n"
            .'lstat("/tmp", "x") => ArgumentCountError: lstat() expects exactly 1 argument, 2 given'."\n"
            .'fileinode("/tmp", "x") => ArgumentCountError: fileinode() expects exactly 1 argument, 2 given'."\n"
            .'fileowner("/tmp", "x") => ArgumentCountError: fileowner() expects exactly 1 argument, 2 given'."\n"
            .'filegroup("/tmp", "x") => ArgumentCountError: filegroup() expects exactly 1 argument, 2 given'."\n"
            .'fileperms("/tmp", "x") => ArgumentCountError: fileperms() expects exactly 1 argument, 2 given'."\n"
            .'fnmatch("*", "a", 0, "x") => ArgumentCountError: fnmatch() expects at most 3 arguments, 4 given'."\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
