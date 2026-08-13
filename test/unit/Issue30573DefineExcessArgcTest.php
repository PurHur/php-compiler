<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for define() (#30573).
 *
 * php-src: Zend/zend_builtin_functions.c / zend_builtin_functions.stub.php
 */
final class Issue30573DefineExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountErrorAndLeavesUndefined(): void
    {
        $path = __DIR__.'/../repro/maintainer_gap_define_excess_argc.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_define_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArgumentCountError: define() expects at most 3 arguments, 4 given\n"
            ."undef\n"
            ."true\n"
            ."defined3\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('ZZZ_DEF4', $out);
    }
}
