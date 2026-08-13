<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for chmod/touch/tempnam (#30551).
 *
 * php-src: ext/standard/filestat.c / file.stub.php
 */
final class Issue30551ChmodTouchTempnamExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_chmod_touch_tempnam_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_chmod_touch_tempnam_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'chmod("/tmp", 0777, "x") => ArgumentCountError: chmod() expects exactly 2 arguments, 3 given'."\n"
            .'chmod("/tmp") => ArgumentCountError: chmod() expects exactly 2 arguments, 1 given'."\n"
            .'touch("/tmp/t", time(), time(), "x") => ArgumentCountError: touch() expects at most 3 arguments, 4 given'."\n"
            .'touch() => ArgumentCountError: touch() expects at least 1 argument, 0 given'."\n"
            .'tempnam("/tmp", "p", "x") => ArgumentCountError: tempnam() expects exactly 2 arguments, 3 given'."\n"
            .'tempnam("/tmp") => ArgumentCountError: tempnam() expects exactly 2 arguments, 1 given'."\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly two arguments', $out);
        $this->assertStringNotContainsString('requires one to three arguments', $out);
    }
}
