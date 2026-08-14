<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionMethod::invokeArgs() excess argc → ArgumentCountError (#30922).
 *
 * php-src: ext/reflection/php_reflection.c — zim_ReflectionMethod_invokeArgs
 */
final class Issue30922ReflectionMethodInvokeArgsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30922_reflection_method_invokeargs_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30922_reflection_method_invokeargs_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionMethod::invokeArgs() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionMethod::invokeArgs() expects exactly 2 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok ACCEPTED:1', $out);
        $this->assertStringNotContainsString('hi ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
