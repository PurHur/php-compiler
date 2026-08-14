<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * WeakMap offsetExists/offsetGet/offsetUnset excess argc → ArgumentCountError (#30909).
 *
 * php-src: Zend/zend_weakrefs.c / zend_weakrefs.stub.php
 */
final class Issue30909WeakMapExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30909_weakmap_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30909_weakmap_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetExists() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetGet() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetUnset() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetExists() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetGet() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakMap::offsetUnset() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString('offsetExists_ok: true', $out);
        $this->assertStringContainsString('offsetGet_ok: 1', $out);
        $this->assertStringContainsString("offsetUnset_ok: 'n'", $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertDoesNotMatchRegularExpression('/^offsetExists: true/m', $out);
        $this->assertDoesNotMatchRegularExpression('/^offsetGet: 1/m', $out);
        $this->assertDoesNotMatchRegularExpression('/^offsetUnset: \'ok\'/m', $out);
    }
}
