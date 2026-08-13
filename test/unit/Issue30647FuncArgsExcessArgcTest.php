<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for func_num_args / func_get_args (#30647).
 *
 * php-src: Zend/zend_builtin_functions.c / zend_builtin_functions.stub.php
 */
final class Issue30647FuncArgsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $path = __DIR__.'/../repro/maintainer_gap_func_num_args_excess_argc.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_func_num_args_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: func_num_args() expects exactly 0 arguments, 1 given\n"
            ."ArgumentCountError: func_get_args() expects exactly 0 arguments, 1 given\n"
            ."num=2 get=a,b\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
