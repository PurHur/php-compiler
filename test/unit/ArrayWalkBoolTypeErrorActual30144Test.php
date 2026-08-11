<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #30144 — array_walk TypeError actuals print false|true not bool
 */
final class ArrayWalkBoolTypeErrorActual30144Test extends TestCase
{
    public function testVmArrayWalkBoolTypeErrorActualLabels(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/array_walk_bool_typeerror.php');
        self::assertNotFalse($code);
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_walk_bool_typeerror.php');
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString(
            'array_walk:array_walk(): Argument #1 ($array) must be of type array, false given',
            $out
        );
        self::assertStringContainsString(
            'array_walk:array_walk(): Argument #1 ($array) must be of type array, true given',
            $out
        );
        self::assertStringContainsString(
            'array_walk_recursive:array_walk_recursive(): Argument #1 ($array) must be of type array, false given',
            $out
        );
        self::assertStringContainsString(
            'array_walk_recursive:array_walk_recursive(): Argument #1 ($array) must be of type array, true given',
            $out
        );
        self::assertStringNotContainsString('bool given', $out);
    }
}
