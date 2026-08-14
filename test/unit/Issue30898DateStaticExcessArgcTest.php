<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTimeZone::listAbbreviations / createFromFormat ArgumentCountError (#30898).
 *
 * php-src: ext/date/php_date.c
 */
final class Issue30898DateStaticExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30898_date_static_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30898_date_static_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'abbr:ArgumentCountError:DateTimeZone::listAbbreviations() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'cff:ArgumentCountError:DateTimeImmutable::createFromFormat() expects at most 3 arguments, 4 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('TypeError', $out);
        $this->assertStringNotContainsString('array(', $out);
    }
}
