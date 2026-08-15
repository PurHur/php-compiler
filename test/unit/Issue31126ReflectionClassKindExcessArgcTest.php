<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass kind/query excess argc (#31126).
 *
 * php-src: ext/reflection/php_reflection.c / php_reflection.stub.php
 */
final class Issue31126ReflectionClassKindExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31126_reflectionclass_kind_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31126_reflectionclass_kind_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'isEnum',
            'isInterface',
            'isTrait',
            'isAbstract',
            'isFinal',
            'isReadOnly',
            'isIterable',
            'getModifiers',
        ] as $method) {
            $this->assertStringContainsString(
                "ArgumentCountError: ReflectionClass::{$method}() expects exactly 0 arguments, 1 given",
                $out
            );
        }
        $this->assertStringContainsString('ok=1,0,0,0,0,0,0,0', $out);
        $this->assertStringNotContainsString('SILENT', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
