<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for get_resource_type (#30707).
 *
 * php-src: Zend/zend_builtin_functions.c
 */
final class Issue30707GetResourceTypeExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30707_get_resource_type_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30707_get_resource_type_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "excess_2:ArgumentCountError:get_resource_type() expects exactly 1 argument, 2 given\n"
            ."excess_3:ArgumentCountError:get_resource_type() expects exactly 1 argument, 3 given\n"
            ."ok:stream\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
