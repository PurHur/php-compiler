<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * WeakMap::count() excess argc → ArgumentCountError (#31129).
 *
 * php-src: Zend/zend_weakrefs.c / zend_weakrefs.stub.php
 */
final class Issue31129WeakMapCountExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31129_weakmap_count_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31129_weakmap_count_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::count() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('count_ok: 1', $out);
        $this->assertStringContainsString('count_fn: 1', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertDoesNotMatchRegularExpression('/^count_excess: 1/m', $out);
    }
}
