<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass getMethods/getProperties/getConstructor excess argc (#31033).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue31033ReflectionClassGetMethodsExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31033_reflectionclass_getmethods_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31033_reflectionclass_getmethods_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getMethods() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getProperties() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::getConstructor() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=__construct,1,1,22', $out);
        $this->assertStringNotContainsString('SILENT', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
