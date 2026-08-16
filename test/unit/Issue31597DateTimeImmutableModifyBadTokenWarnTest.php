<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTimeImmutable::modify('@@@') Warning detail matches Zend (#31597).
 *
 * php-src: ext/date/php_date.c / timelib unexpected-character diagnostics
 * (sibling of DateInterval::createFromDateString #31575).
 */
final class Issue31597DateTimeImmutableModifyBadTokenWarnTest extends TestCase
{
    public function testVmMatchesZendUnexpectedCharacterWarning(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_datetimeimmutable_modify_warn.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_datetimeimmutable_modify_warn.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ret=false\n"
            ."warning=DateTimeImmutable::modify(): Failed to parse time string (@@@) at position 0 (@): Unexpected character\n",
            $out
        );
    }
}
