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
}
