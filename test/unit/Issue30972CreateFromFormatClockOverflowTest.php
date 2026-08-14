<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTime::createFromFormat invalid clock overflows like Zend (#30972).
 *
 * php-src: ext/date/lib/parse_date.c — timelib_parse_from_format
 */
final class Issue30972CreateFromFormatClockOverflowTest extends TestCase
{
    public function testVmClockOverflowMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30972_createfromformat_clock_overflow.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30972_createfromformat_clock_overflow.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString('H:i 25:00 01:00:00 next=1', $out);
        $this->assertStringContainsString('H:i 12:60 13:00:00', $out);
        $this->assertStringContainsString('imm 01:00:00', $out);
        $this->assertStringContainsString('fn 01:00:00', $out);
        $this->assertStringContainsString('cal 2024-03-02 12:00:00', $out);
        $this->assertStringContainsString('bang 1970-01-02 01:00:00', $out);
        $this->assertStringContainsString('wc=1 key=5 msg=The parsed time was invalid', $out);
        $this->assertStringContainsString('wc=1 key=19 msg=The parsed date was invalid', $out);
        $this->assertStringContainsString("parse h=25 wc=1 w5=The parsed time was invalid", $out);
        $this->assertStringNotContainsString('err=false', $out);
        $this->assertStringNotContainsString('H:i 25:00 false', $out);
    }
}
