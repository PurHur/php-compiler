<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTime::modify('now'|'UTC'|whitespace) succeeds like Zend (#31603).
 *
 * php-src: ext/date/php_date.c — php_date_modify / timelib
 */
final class Issue31603DateTimeModifyNowUtcWsTest extends TestCase
{
    public function testVmMatchesZendNowUtcWhitespace(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_datetime_modify_now_utc_ws.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_datetime_modify_now_utc_ws.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "\"now\" ret=obj fmt=2020-01-01 12:00:00\n"
            ."\"UTC\" ret=obj fmt=2020-01-01 12:00:00\n"
            ."\" \" ret=obj fmt=2020-01-01 12:00:00\n"
            ."\"\\t\" ret=obj fmt=2020-01-01 12:00:00\n"
            ."\"tomorrow\" ret=obj fmt=2020-01-02 00:00:00\n",
            $out
        );
    }
}
