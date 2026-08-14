<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionClass::newInstanceArgs / newInstanceWithoutConstructor excess argc (#30923).
 *
 * php-src: ext/reflection/php_reflection.c
 */
final class Issue30923ReflectionClassNewInstanceExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30923_reflection_class_newinstance_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30923_reflection_class_newinstance_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::newInstanceArgs() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClass::newInstanceWithoutConstructor() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('args_ok ACCEPTED:1', $out);
        $this->assertStringContainsString('args_omit ACCEPTED:0', $out);
        $this->assertStringContainsString("niwc_ok ACCEPTED:'ok'", $out);
        $this->assertStringNotContainsString('args_hi ACCEPTED', $out);
        $this->assertStringNotContainsString('niwc_hi ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
