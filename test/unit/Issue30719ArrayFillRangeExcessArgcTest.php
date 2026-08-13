<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * array_fill / array_fill_keys / range ArgumentCountError wording matches Zend (#30719).
 *
 * php-src: ext/standard/array.c
 */
final class Issue30719ArrayFillRangeExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30719_array_fill_range_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30719_array_fill_range_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'fill_hi:ArgumentCountError:array_fill() expects exactly 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'fill_lo:ArgumentCountError:array_fill() expects exactly 3 arguments, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'keys_hi:ArgumentCountError:array_fill_keys() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'keys_lo:ArgumentCountError:array_fill_keys() expects exactly 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'range_hi:ArgumentCountError:range() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString(
            'range_lo:ArgumentCountError:range() expects at least 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_fill:1', $out);
        $this->assertStringContainsString('ok_keys:1', $out);
        $this->assertStringContainsString('ok_range:1', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
        $this->assertStringNotContainsString('requires two or three', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
