<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionMethod kind/query excess argc (#31127).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue31127ReflectionMethodKindExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31127_reflectionmethod_kind_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31127_reflectionmethod_kind_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'isPublic' => 'ReflectionMethod::isPublic',
            'isPrivate' => 'ReflectionMethod::isPrivate',
            'isProtected' => 'ReflectionMethod::isProtected',
            'isStatic' => 'ReflectionFunctionAbstract::isStatic',
            'isFinal' => 'ReflectionMethod::isFinal',
            'isAbstract' => 'ReflectionMethod::isAbstract',
            'isConstructor' => 'ReflectionMethod::isConstructor',
            'isDestructor' => 'ReflectionMethod::isDestructor',
            'getModifiers' => 'ReflectionMethod::getModifiers',
            'getDeclaringClass' => 'ReflectionMethod::getDeclaringClass',
            'getPrototype' => 'ReflectionMethod::getPrototype',
        ] as $method => $display) {
            $this->assertStringContainsString(
                "ArgumentCountError: {$display}() expects exactly 0 arguments, 1 given",
                $out
            );
        }
        $this->assertStringContainsString('ok=1,0,0,0,0,0,0,0,1,T,A::m', $out);
        $this->assertStringContainsString(
            'noproto: ReflectionException: Method T::m does not have a prototype',
            $out
        );
        $this->assertStringContainsString(
            'already: ArgumentCountError: ReflectionFunctionAbstract::isClosure() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringNotContainsString('SILENT', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
