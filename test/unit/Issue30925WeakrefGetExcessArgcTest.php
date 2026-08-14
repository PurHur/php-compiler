<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * WeakReference::get() excess argc → ArgumentCountError (#30925).
 *
 * php-src: Zend/zend_weakrefs.c zim_WeakReference_get / zend_weakrefs.stub.php
 */
final class Issue30925WeakrefGetExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30925_weakref_get_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30925_weakref_get_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: WeakReference::get() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_get=y', $out);
        $this->assertStringContainsString('ok_dead=NULL', $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('true', $out);
    }
}
