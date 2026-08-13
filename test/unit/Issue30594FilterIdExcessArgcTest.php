<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filter_id() excess argc → ArgumentCountError (#30594).
 *
 * php-src: ext/filter/filter.c
 */
final class Issue30594FilterIdExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30594_filter_id_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30594_filter_id_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError:filter_id() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError:filter_id() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('filter_id_ok=257', $out);
        $this->assertStringNotContainsString('NO_THROW', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
