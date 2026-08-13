<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for timezone_abbreviations_list (#30681).
 *
 * php-src: ext/date/php_date.c
 */
final class Issue30681TimezoneAbbreviationsListExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30681_timezone_abbreviations_list_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30681_timezone_abbreviations_list_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'excess:ArgumentCountError:timezone_abbreviations_list() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_type:array', $out);
        $this->assertStringContainsString('ok_nonempty:1', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
