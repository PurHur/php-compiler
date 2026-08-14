<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ReflectionEnum::isBacked / getBackingType excess argc (#30929).
 *
 * php-src: ext/reflection/php_reflection.c
 */
final class Issue30929ReflectionEnumIsBackedExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30929_reflection_enum_isbacked_getbackingtype_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30929_reflection_enum_isbacked_getbackingtype_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionEnum::isBacked() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: ReflectionEnum::getBackingType() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('isBacked_ok ACCEPTED:true', $out);
        $this->assertStringContainsString("getBackingType_ok ACCEPTED:'int'", $out);
        $this->assertStringNotContainsString('isBacked_hi ACCEPTED', $out);
        $this->assertStringNotContainsString('getBackingType_hi ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
