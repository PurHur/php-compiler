<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTime(Immutable)::setDate(null,…) year 0 / −0001 vs Zend (#31619).
 *
 * php-src: ext/date/php_date.c — date_date_set / zim_DateTime_setDate (timelib year 0).
 */
final class Issue31619DateTimeSetDateNullYearTest extends TestCase
{
    public function testVmFormatsYearZeroLikeZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_datetime_setdate_null_year.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_datetime_setdate_null_year.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame(
            "DEP:DateTime::setDate(): Passing null to parameter #1 (\$year) of type int is deprecated\n"
            ."DEP:DateTime::setDate(): Passing null to parameter #2 (\$month) of type int is deprecated\n"
            ."DEP:DateTime::setDate(): Passing null to parameter #3 (\$day) of type int is deprecated\n"
            ."DateTime::setDate(null,null,null)=-0001-11-30\n"
            ."DEP:DateTimeImmutable::setDate(): Passing null to parameter #1 (\$year) of type int is deprecated\n"
            ."DEP:DateTimeImmutable::setDate(): Passing null to parameter #2 (\$month) of type int is deprecated\n"
            ."DEP:DateTimeImmutable::setDate(): Passing null to parameter #3 (\$day) of type int is deprecated\n"
            ."DateTimeImmutable::setDate(null,null,null)=-0001-11-30\n"
            ."DEP:DateTime::setDate(): Passing null to parameter #1 (\$year) of type int is deprecated\n"
            ."DateTime::setDate(null,6,15)=0000-06-15\n",
            $out
        );
    }

    public function testVmStrictTypesStillTypeErrors(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
try {
    (new DateTime('2020-01-01'))->setDate(null, 1, 1);
    echo "fail\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'setdate_null_strict.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        $this->assertSame("TypeError\n", $out);
    }
}
