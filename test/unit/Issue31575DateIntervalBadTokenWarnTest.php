<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateInterval::createFromDateString('@@@') Warning detail matches Zend (#31575).
 *
 * php-src: ext/date/php_date.c / ext/date/lib/parse_date.re — unexpected-character diagnostics
 */
final class Issue31575DateIntervalBadTokenWarnTest extends TestCase
{
    public function testVmMatchesZendUnexpectedCharacterWarning(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_dateinterval_createfromdatestring_warn.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_dateinterval_createfromdatestring_warn.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "return=false\n"
            ."warning=DateInterval::createFromDateString(): Unknown or bad format (@@@) at position 0 (@): Unexpected character\n",
            $out
        );
    }
}
