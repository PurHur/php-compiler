<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionFunctionAbstract introspection + getClosure excess argc (#30924).
 *
 * php-src: ext/reflection/php_reflection.c
 */
final class Issue30924ReflectionFunctionAbstractExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30924_reflection_function_abstract_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30924_reflection_function_abstract_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionFunctionAbstract::getNumberOfParameters() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionFunctionAbstract::isVariadic() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionFunction::getClosure() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=2,1,1,0,1,0,0,0,array,Closure', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
