<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stripcslashes() ArgumentCountError wording matches Zend (#30704).
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(stripcslashes)
 */
final class Issue30704StripcslashesExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30704_stripcslashes_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30704_stripcslashes_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'hi:ArgumentCountError:stripcslashes() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'lo:ArgumentCountError:stripcslashes() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
