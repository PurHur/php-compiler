<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * highlight_string / get_meta_tags ArgumentCountError wording matches Zend (#30723).
 *
 * php-src: ext/standard/url_scanner_ex.re / basic_functions.c
 */
final class Issue30723HighlightMetaExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30723_highlight_meta_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30723_highlight_meta_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'hs_hi:ArgumentCountError:highlight_string() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'hs_lo:ArgumentCountError:highlight_string() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'gmt_hi:ArgumentCountError:get_meta_tags() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'gmt_lo:ArgumentCountError:get_meta_tags() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok_hs:1', $out);
        $this->assertStringContainsString('ok_gmt:1', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
