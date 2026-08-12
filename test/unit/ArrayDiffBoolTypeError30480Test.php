<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #30480 — array_diff* variadic TypeError cites bool not true|false
 */
final class ArrayDiffBoolTypeError30480Test extends TestCase
{
    public function testVmVariadicArraySetOpsBoolTypeName(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_30480_array_diff_bool_typeerror.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_30480_array_diff_bool_typeerror.php');
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString(
            'array_diff:array_diff(): Argument #3 must be of type array, bool given',
            $out
        );
        self::assertStringContainsString(
            'array_intersect:array_intersect(): Argument #3 must be of type array, bool given',
            $out
        );
        self::assertStringContainsString(
            'array_merge:array_merge(): Argument #2 must be of type array, bool given',
            $out
        );
        self::assertStringNotContainsString('true given', $out);
        self::assertStringNotContainsString('false given', $out);
    }
}
