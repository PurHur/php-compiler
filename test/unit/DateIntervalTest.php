<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmDateInterval;
use PHPCompiler\Runtime;
use PHPCompiler\VM\NativeDateMalformedIntervalException;
use PHPUnit\Framework\TestCase;

/** @covers issue #7278 */
final class DateIntervalTest extends TestCase
{
    public function testParseAndFormatParity(): void
    {
        $parsed = VmDateInterval::parseSpec('P1Y2M3DT4H5M6S');
        $this->assertSame(
            '1 2 3 4 5 6',
            VmDateInterval::format(array_merge($parsed, ['days' => false]), '%y %m %d %h %i %s')
        );
        $this->assertSame('123', VmDateInterval::format(array_merge($parsed, ['days' => false]), '%y%m%d'));
        $this->assertSame('1', VmDateInterval::format(['y' => 0, 'm' => 0, 'd' => 1, 'h' => 0, 'i' => 0, 's' => 0, 'f' => 0.0, 'invert' => 0, 'days' => false], '%d'));
    }

    public function testVmDateIntervalFormatAndProceduralAlias(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
echo $interval->format('%y%m%d'), "\n";
echo class_exists('DateInterval', false) ? '1' : '0', "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'date_interval_format.php'));
        $this->assertSame("1\n001\n1\n", ob_get_clean());
    }

    public function testDateIntervalTypeErrorOnProceduralAlias(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    date_interval_format([], '%d');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo "type-error\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'date_interval_format_type.php'));
        $this->assertSame("type-error\n", ob_get_clean());
    }

    public function testMalformedSpecThrowsException(): void
    {
        if (CompilerVersion::advertisesDateExceptionHierarchy()) {
            $this->expectException(NativeDateMalformedIntervalException::class);
        } else {
            $this->expectException(\Exception::class);
        }
        $this->expectExceptionMessage('Unknown or bad format (bad)');
        VmDateInterval::parseSpec('bad');
    }

    public function testVmDateIntervalMalformedSpecThrowsTypedException(): void
    {
        if (!CompilerVersion::advertisesDateExceptionHierarchy()) {
            $this->markTestSkipped('DateMalformedIntervalStringException requires PHP 8.3+ date hierarchy');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    new DateInterval('bad');
    echo "no throw\n";
} catch (DateMalformedIntervalStringException $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'date_interval_malformed.php'));
        $this->assertSame("Unknown or bad format (bad)\n", ob_get_clean());
    }
}
