<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateInterval::__construct excess argc → ArgumentCountError (#30601).
 *
 * php-src: ext/date/php_date.c — zim_DateInterval___construct
 */
final class Issue30601DateIntervalCtorExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_dateinterval_ctor_excess_argc_30601.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_dateinterval_ctor_excess_argc_30601.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "DateInterval::__construct() expects exactly 1 argument, 2 given\n"
            ."DateInterval::__construct() expects exactly 1 argument, 0 given\n"
            ."OK 1\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
