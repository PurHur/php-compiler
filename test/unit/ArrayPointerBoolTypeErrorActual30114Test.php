<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #30114 — array pointer TypeError actuals print false|true not bool
 */
final class ArrayPointerBoolTypeErrorActual30114Test extends TestCase
{
    public function testVmArrayPointerBoolTypeErrorActualLabels(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_30114_array_pointer_bool_typeerror.php');
        self::assertNotFalse($code);
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_30114.php');
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString('reset:reset(): Argument #1 ($array) must be of type array, false given', $out);
        self::assertStringContainsString('reset:reset(): Argument #1 ($array) must be of type array, true given', $out);
        self::assertStringContainsString('end:end(): Argument #1 ($array) must be of type array, null given', $out);
        self::assertStringNotContainsString('bool given', $out);
    }
}
