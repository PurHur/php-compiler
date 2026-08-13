<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * getdate/strtotime ArgumentCountError wording matches Zend (#30714).
 *
 * php-src: ext/date/php_date.c
 */
final class Issue30714GetdateStrtotimeArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30714_getdate_strtotime_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30714_getdate_strtotime_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'getdate:ArgumentCountError:getdate() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'strtotime_hi:ArgumentCountError:strtotime() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'strtotime_lo:ArgumentCountError:strtotime() expects at least 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok_getdate:1', $out);
        $this->assertStringContainsString('ok_strtotime:1', $out);
        $this->assertStringNotContainsString('accepts at most', $out);
        $this->assertStringNotContainsString('expects 1 or 2', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
