<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ParamArgumentCountError;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #29953 — method-scoped closure TypeError uses Class::{closure}
 */
final class ClosureMethodScopeTypeerrorNameTest extends TestCase
{
    public function testFormatUserFunctionNameScopesClosures(): void
    {
        self::assertSame('{closure}', ParamArgumentCountError::formatUserFunctionName('{anonymous}#1'));
        self::assertSame('{closure}', ParamArgumentCountError::formatUserFunctionName('{closure}_0'));
        self::assertSame(
            'C::{closure}',
            ParamArgumentCountError::formatUserFunctionName('C::{anonymous}#1')
        );
        self::assertSame(
            'C::{closure}',
            ParamArgumentCountError::formatUserFunctionName('C::{closure}_3')
        );
        self::assertSame('C::m', ParamArgumentCountError::formatUserFunctionName('C::m'));
    }

    public function testMethodScopedClosureTypeErrorMatchesZendShape(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/issue_29953_closure_method_scope_typeerror.php'
        );
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_29953.php');
        ob_start();
        $runtime->run($block);
        $out = (string) ob_get_clean();
        // PROFILE≥8.4: C::{closure:C::m():N}; older profiles: C::{closure} (#29953 / #30076).
        self::assertMatchesRegularExpression(
            '/C::\{closure(?::C::m\(\):\d+)?\}\(\): Argument #1 \(\$x\) must be of type int, null given/',
            $out
        );
        self::assertMatchesRegularExpression(
            '/C::\{closure(?::C::s\(\):\d+)?\}\(\): Argument #1 \(\$x\) must be of type C, null given/',
            $out
        );
        self::assertMatchesRegularExpression(
            '/^\{closure(?::[^:]+:\d+)?\}\(\): Argument #1 \(\$x\) must be of type int, null given/m',
            $out
        );
        self::assertSame(3, preg_match_all('/C::\{closure/', $out));
        self::assertSame(1, preg_match_all('/^\{closure(?::[^:]+:\d+)?\}\(\):/m', $out));
    }
}
