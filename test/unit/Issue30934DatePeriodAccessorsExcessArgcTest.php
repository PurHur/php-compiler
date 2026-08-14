<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DatePeriod accessors excess argc → ArgumentCountError (#30934).
 *
 * php-src: ext/date/php_date.c
 */
final class Issue30934DatePeriodAccessorsExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30934_dateperiod_accessors_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30934_dateperiod_accessors_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'interval:ArgumentCountError:DatePeriod::getDateInterval() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'start:ArgumentCountError:DatePeriod::getStartDate() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'end:ArgumentCountError:DatePeriod::getEndDate() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'rec:ArgumentCountError:DatePeriod::getRecurrences() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
    }
}
