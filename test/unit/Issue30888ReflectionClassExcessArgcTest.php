<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass / Function / Parameter excess argc → ArgumentCountError (#30888).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue30888ReflectionClassExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30888_reflectionclass_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30888_reflectionclass_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getShortName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getFileName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::hasMethod() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getMethod() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getConstant() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionFunctionAbstract::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionParameter::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ok=DateTime,DateTime,1,format,Y-m-d\\TH:i:sP,1,strlen,string',
            $out
        );
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
