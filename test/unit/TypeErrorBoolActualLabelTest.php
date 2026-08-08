<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29097 — TypeError actual bool prints true/false (zend_execute.c)
 */
final class TypeErrorBoolActualLabelTest extends TestCase
{
    public function testIssue29097VmTypeErrorBoolActualLabels(): void
    {
        $code = file_get_contents(dirname(__DIR__) . '/repro/issue_29097_typeerror_bool_text.php');
        self::assertNotFalse($code);
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29097.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('count_false:count(): Argument #1 ($value) must be of type Countable|array, false given', $out);
        self::assertStringContainsString('count_true:count(): Argument #1 ($value) must be of type Countable|array, true given', $out);
        self::assertStringContainsString('need_array_false:need_array(): Argument #1 ($x) must be of type array, false given', $out);
        self::assertStringContainsString('count_null:count(): Argument #1 ($value) must be of type Countable|array, null given', $out);
        self::assertStringNotContainsString('bool given', $out);
    }
}
