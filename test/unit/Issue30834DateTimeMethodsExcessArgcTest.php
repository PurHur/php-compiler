<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTime methods excess argc → ArgumentCountError (#30834).
 *
 * php-src: ext/date/php_date.c
 */
final class Issue30834DateTimeMethodsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_datetime_methods_excess_argc_30834.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_datetime_methods_excess_argc_30834.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'DateTime::format() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'DateTime::setTime() expects at most 4 arguments, 5 given',
            $out
        );
        $this->assertStringContainsString(
            'DateTime::diff() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'DateTimeZone::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'DateInterval::format() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('ok_format: 2020', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
