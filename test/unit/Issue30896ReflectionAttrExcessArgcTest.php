<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionAttribute / type / ClassConstant / Property excess argc → ArgumentCountError (#30896).
 *
 * php-src: ext/reflection/php_reflection.c
 */
final class Issue30896ReflectionAttrExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30896_reflection_attr_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30896_reflection_attr_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionAttribute::getName() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionAttribute::getArguments() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionNamedType::isBuiltin() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionUnionType::getTypes() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionClassConstant::getValue() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionProperty::getValue() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('ok=Issue30896AttrA,string,ATOM,x,1', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
