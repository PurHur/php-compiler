<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3072 */
final class DateTimeTest extends TestCase
{
    public function testDateTimeConstructFormatUtc(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$dt = new DateTime('2026-05-29', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d');
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_format.php'));
        $this->assertSame('2026-05-29', ob_get_clean());
    }

    public function testClassExistsDateTimeAndDateTimeZone(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo class_exists('DateTime') && class_exists('DateTimeZone') ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_classes.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testDateTimeGetTimestamp(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$dt = new DateTime('2026-05-29 12:00:00', new DateTimeZone('UTC'));
echo $dt->getTimestamp() === 1780056000 ? '1' : '0';
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_ts.php'));
        $this->assertSame('1', ob_get_clean());
    }

    public function testDateTimeModifyRelativeDay(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$dt = new DateTime('2020-01-01', new DateTimeZone('UTC'));
$dt->modify('+1 day');
echo $dt->format('Y-m-d');
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_modify.php'));
        $this->assertSame('2020-01-02', ob_get_clean());
    }

    public function testDateTimeSetDateAndSetTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$dt = new DateTime('2020-01-15 10:30:45', new DateTimeZone('UTC'));
$dt->setDate(2021, 6, 1);
$dt->setTime(14, 5, 30);
echo $dt->format('Y-m-d H:i:s'), "\n";
$immutable = new DateTimeImmutable('2020-01-15 10:30:45', new DateTimeZone('UTC'));
echo $immutable->setDate(2022, 1, 2)->format('Y-m-d'), "\n";
echo $immutable->format('Y-m-d'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_setdate_settime.php'));
        $this->assertSame("2021-06-01 14:05:30\n2022-01-02\n2020-01-15\n", ob_get_clean());
    }

    public function testDateTimeInterfaceRegistration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo interface_exists('DateTimeInterface') ? '1' : '0', "\n";
echo (new DateTime('2026-01-01')) instanceof DateTimeInterface ? '1' : '0', "\n";
echo DateTimeInterface::ATOM, "\n";
function accepts(DateTimeInterface $dt): string { return $dt->format('Y-m-d'); }
echo accepts(new DateTime('2026-06-07')), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_interface.php'));
        $this->assertSame("1\n1\nY-m-d\\TH:i:sP\n2026-06-07\n", ob_get_clean());
    }

    /** @covers issue #22271 */
    public function testDateTimeClassFormatConstantsDefined(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo defined('DateTime::ATOM') ? '1' : '0', "\n";
echo defined('DateTimeImmutable::ATOM') ? '1' : '0', "\n";
$r = new ReflectionClass(DateTime::class);
echo count($r->getConstants()), "\n";
echo $r->hasConstant('RFC3339_EXTENDED') ? '1' : '0', "\n";
echo DateTime::ATOM, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_format_constants.php'));
        $this->assertSame("1\n1\n14\n1\nY-m-d\\TH:i:sP\n", ob_get_clean());
    }

    /** @covers issue #10946 */
    public function testDateTimeAddSubGetTimezoneSetTimestamp(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$dt = new DateTime('2020-01-01', new DateTimeZone('UTC'));
echo $dt->getTimezone()->getName(), "\n";
$dt->add(new DateInterval('P1D'));
echo $dt->format('Y-m-d'), "\n";
$dt->sub(new DateInterval('P1D'));
echo $dt->format('Y-m-d'), "\n";
$dt->setTimestamp(86400);
echo $dt->getTimestamp(), "\n";
$immutable = new DateTimeImmutable('2020-01-01', new DateTimeZone('UTC'));
echo $immutable->add(new DateInterval('P1D'))->format('Y-m-d'), "\n";
echo $immutable->format('Y-m-d'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'datetime_add_sub.php'));
        $this->assertSame("UTC\n2020-01-02\n2020-01-01\n86400\n2020-01-02\n2020-01-01\n", ob_get_clean());
    }
}
