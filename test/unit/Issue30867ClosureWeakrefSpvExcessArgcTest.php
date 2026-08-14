<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Closure::bindTo / WeakReference::create / SensitiveParameterValue::getValue excess argc (#30867).
 *
 * php-src: Zend/zend_closures.c, Zend/zend_weakrefs.c, Zend/zend_attributes.stub.php
 */
final class Issue30867ClosureWeakrefSpvExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30867_closure_weakref_spv_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30867_closure_weakref_spv_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: Closure::bindTo() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: WeakReference::create() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: SensitiveParameterValue::getValue() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok_bind=1', $out);
        $this->assertStringContainsString('ok_weak=y', $out);
        $this->assertStringContainsString('ok_spv=secret', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
