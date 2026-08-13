<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_list() excess argc → ArgumentCountError (#30675).
 *
 * php-src: ext/filter/filter.c
 */
final class Issue30675FilterListExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30675_filter_list_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30675_filter_list_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError:filter_list() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString("filter_list_ok\n", $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
