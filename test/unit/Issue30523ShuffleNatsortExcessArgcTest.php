<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for shuffle/natsort/natcasesort (#30523).
 *
 * php-src: ext/standard/array.c / basic_functions.stub.php
 */
final class Issue30523ShuffleNatsortExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_shuffle_natsort_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_shuffle_natsort_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "shuffle() expects exactly 1 argument, 2 given\n"
            ."natsort() expects exactly 1 argument, 2 given\n"
            ."natcasesort() expects exactly 1 argument, 2 given\n"
            ."shuffle() expects exactly 1 argument, 0 given\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
