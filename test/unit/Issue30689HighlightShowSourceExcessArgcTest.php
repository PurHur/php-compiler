<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * highlight_file/show_source ArgumentCountError wording matches Zend (#30689).
 *
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.stub.php
 */
final class Issue30689HighlightShowSourceExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30689_highlight_show_source_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30689_highlight_show_source_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "highlight_file:ArgumentCountError:highlight_file() expects at most 2 arguments, 3 given\n"
            ."show_source:ArgumentCountError:show_source() expects at most 2 arguments, 3 given\n"
            ."hf_lo:ArgumentCountError:highlight_file() expects at least 1 argument, 0 given\n"
            ."ok:1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }
}
