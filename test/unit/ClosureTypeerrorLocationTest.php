<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ParamArgumentCountError;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #30076 — PHP 8.4 closure TypeError includes defining function/file + line
 */
final class ClosureTypeerrorLocationTest extends TestCase
{
    public function testFormatUserFunctionNameKeepsRichClosureLabels(): void
    {
        self::assertSame(
            '{closure:outer():3}',
            ParamArgumentCountError::formatUserFunctionName('{closure:outer():3}')
        );
        self::assertSame(
            'C::{closure:C::m():4}',
            ParamArgumentCountError::formatUserFunctionName('C::{closure:C::m():4}')
        );
        self::assertSame(
            '{closure:{closure:outer():3}:4}',
            ParamArgumentCountError::formatUserFunctionName('{closure:{closure:outer():3}:4}')
        );
        self::assertSame('{closure}', ParamArgumentCountError::formatUserFunctionName('{anonymous}#1'));
    }

    public function testVmClosureTypeErrorMatchesZend84Shape(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = file_get_contents(
                dirname(__DIR__).'/repro/issue_30076_closure_typeerror_location.php'
            );
            self::assertNotFalse($code);
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'issue_30076.php');
            ob_start();
            $runtime->run($block);
            $out = (string) ob_get_clean();
            self::assertStringContainsString(
                '{closure:outer():4}(): Argument #1 ($x) must be of type int, null given',
                $out
            );
            self::assertStringContainsString(
                '{closure:outer():10}(): Return value must be of type int, null returned',
                $out
            );
            self::assertMatchesRegularExpression(
                '/\{closure:[^:]+:\d+\}\(\): Return value must be of type int, null returned/',
                $out
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
