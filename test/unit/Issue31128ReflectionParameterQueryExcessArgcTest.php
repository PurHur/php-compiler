<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionParameter query excess argc (#31128).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue31128ReflectionParameterQueryExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31128_reflection_parameter_query_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31128_reflection_parameter_query_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'isOptional',
            'isPassedByReference',
            'canBePassedByValue',
            'isDefaultValueAvailable',
            'isDefaultValueConstant',
            'isVariadic',
            'hasType',
            'isPromoted',
            'allowsNull',
        ] as $method) {
            $this->assertStringContainsString(
                "ArgumentCountError: ReflectionParameter::{$method}() expects exactly 0 arguments, 1 given",
                $out
            );
        }
        $this->assertStringContainsString('ok=1,0,1,1,0,0,1,0,0', $out);
        $this->assertStringContainsString(
            'already: ArgumentCountError: ReflectionParameter::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringNotContainsString('SILENT', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
