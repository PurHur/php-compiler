<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for flock() (#30583).
 *
 * php-src: ext/standard/file.c / basic_functions.stub.php
 */
final class Issue30583FlockExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_flock_excess_argc_30583.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_flock_excess_argc_30583.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('flock() expects at most 3 arguments, 4 given', $out);
        $this->assertStringContainsString("2ok\n", $out);
        $this->assertStringContainsString('3ok', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
    }
}
