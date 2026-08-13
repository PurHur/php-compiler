<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for strip_tags() (#30592).
 *
 * php-src: ext/standard/string.c
 */
final class Issue30592StripTagsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_strip_tags_excess_argc_30592.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_strip_tags_excess_argc_30592.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "strip_tags() expects at most 2 arguments, 3 given\n"
            ."strip_tags() expects at least 1 argument, 0 given\n"
            ."ok\n"
            ."<b>ok</b>\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('compiler build', $out);
    }
}
