<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DatePeriod null $end — soft-null E_DEPRECATED then recurrence Exception (#31527).
 *
 * php-src: ext/date/php_date.c — date_period_construct
 */
final class Issue31527DatePeriodNullEndTest extends TestCase
{
    public function testVmNullEndDeprecationThenRecurrenceException(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_dateperiod_null_end.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_dateperiod_null_end.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'DEP:DatePeriod::__construct(): Passing null to parameter #3 ($end) of type int is deprecated',
            $out
        );
        $this->assertStringContainsString(
            'Exception: DatePeriod::__construct(): Recurrence count must be greater than 0',
            $out
        );
        $this->assertStringNotContainsString('TypeError:', $out);
    }

    public function testVmStrictTypesNullEndSignatureTypeError(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
    new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'dateperiod_null_end_strict.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "TypeError: DatePeriod::__construct() accepts (DateTimeInterface, DateInterval, int [, int]), "
            ."or (DateTimeInterface, DateInterval, DateTime [, int]), or (string [, int]) as arguments\n",
            $out
        );
    }
}
