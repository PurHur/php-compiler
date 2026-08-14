<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Closure::fromCallable() excess / missing argc (#30930).
 *
 * php-src: Zend/zend_closures.c — zim_Closure_fromCallable
 */
final class Issue30930FromCallableExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30930_fromcallable_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30930_fromcallable_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: Closure::fromCallable() expects exactly 1 argument, 0 given',
            $out
        );
        $this->assertStringContainsString("ok=2\n", $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('OK:', $out);
        $this->assertStringNotContainsString('OK0:', $out);
    }
}
