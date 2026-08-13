<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DateTime/DateTimeImmutable::__construct excess argc → ArgumentCountError (#30600).
 *
 * php-src: ext/date/php_date.c — zim_DateTime___construct / zim_DateTimeImmutable___construct
 */
final class Issue30600DateTimeCtorExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_datetime_ctor_excess_argc_30600.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_datetime_ctor_excess_argc_30600.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "DateTime DateTime::__construct() expects at most 2 arguments, 3 given\n"
            ."DateTimeImmutable DateTimeImmutable::__construct() expects at most 2 arguments, 3 given\n"
            ."DT_OK yes\n"
            ."DTI_OK 2020-01-01\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
    }
}
