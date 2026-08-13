<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for is_scalar / is_numeric / is_resource (#30687).
 *
 * php-src: Zend/zend_builtin_functions.c
 */
final class Issue30687IsPredicatesExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30687_is_predicates_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30687_is_predicates_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "is_scalar_0:ArgumentCountError:is_scalar() expects exactly 1 argument, 2 given\n"
            ."is_scalar_1:ArgumentCountError:is_scalar() expects exactly 1 argument, 3 given\n"
            ."is_scalar_2:OK:true\n"
            ."is_numeric_0:ArgumentCountError:is_numeric() expects exactly 1 argument, 2 given\n"
            ."is_numeric_1:ArgumentCountError:is_numeric() expects exactly 1 argument, 3 given\n"
            ."is_numeric_2:OK:true\n"
            ."is_resource_0:ArgumentCountError:is_resource() expects exactly 1 argument, 2 given\n"
            ."is_resource_1:ArgumentCountError:is_resource() expects exactly 1 argument, 3 given\n"
            ."is_resource_2:OK:false\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly one argument', $out);
    }
}
