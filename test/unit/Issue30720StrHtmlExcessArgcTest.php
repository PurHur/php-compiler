<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * str_word_count / htmlspecialchars_decode / get_html_translation_table
 * ArgumentCountError wording matches Zend (#30720).
 *
 * php-src: ext/standard/string.c / html.c
 */
final class Issue30720StrHtmlExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30720_str_html_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30720_str_html_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'swc_hi:ArgumentCountError:str_word_count() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'swc_lo:ArgumentCountError:str_word_count() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'hsd_hi:ArgumentCountError:htmlspecialchars_decode() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'hsd_lo:ArgumentCountError:htmlspecialchars_decode() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'ghtt_hi:ArgumentCountError:get_html_translation_table() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString('ok_swc:1', $out);
        $this->assertStringContainsString('ok_hsd:1', $out);
        $this->assertStringContainsString('ok_ghtt:1', $out);
        $this->assertStringNotContainsString('accepts one to three', $out);
        $this->assertStringNotContainsString('requires one or two', $out);
        $this->assertStringNotContainsString('accepts zero to three', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
