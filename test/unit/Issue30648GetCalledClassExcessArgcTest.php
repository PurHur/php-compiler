<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for get_called_class() (#30648).
 *
 * php-src: Zend/zend_builtin_functions.c / zend_builtin_functions.stub.php
 */
final class Issue30648GetCalledClassExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $path = __DIR__.'/../repro/maintainer_gap_get_called_class_excess_argc.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_get_called_class_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: get_called_class() expects exactly 0 arguments, 1 given\n"
            ."ArgumentCountError: get_called_class() expects exactly 0 arguments, 2 given\n"
            ."ok=A\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('takes no arguments', $out);
    }
}
