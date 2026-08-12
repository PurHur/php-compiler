<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for filestat path predicates (#30544).
 *
 * php-src: ext/standard/filestat.c / file.stub.php
 */
final class Issue30544FilestatPredicatesExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_filestat_predicates_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_filestat_predicates_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "is_file() expects exactly 1 argument, 2 given\n"
            ."is_dir() expects exactly 1 argument, 2 given\n"
            ."is_link() expects exactly 1 argument, 2 given\n"
            ."is_readable() expects exactly 1 argument, 2 given\n"
            ."is_writable() expects exactly 1 argument, 2 given\n"
            ."is_executable() expects exactly 1 argument, 2 given\n"
            ."file_exists() expects exactly 1 argument, 2 given\n"
            ."realpath() expects exactly 1 argument, 2 given\n"
            ."is_file() expects exactly 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
