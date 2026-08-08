<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ParamArgumentCountError;
use PHPUnit\Framework\TestCase;

/**
 * #29095 — named-arg missing required uses Zend "Argument #N ($name) not passed".
 */
final class NamedArgMissingRequiredNotPassedTest extends TestCase
{
    public function testVmNamedAndUnpackMatchZendNotPassedMessage(): void
    {
        $rt = new Runtime();
        $code = file_get_contents(__DIR__.'/../repro/named_arg_missing_required_not_passed.php');
        $this->assertNotFalse($code);
        $block = $rt->parseAndCompile($code, 'named_arg_missing_required_not_passed.php');
        $this->assertNotNull($block);
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString(
            'ArgumentCountError: f(): Argument #1 ($a) not passed',
            $out
        );
        $this->assertStringContainsString('C::m(): Argument #1 ($a) not passed', $out);
        $this->assertStringContainsString('C::s(): Argument #1 ($a) not passed', $out);
        $this->assertStringContainsString('C::__invoke(): Argument #1 ($a) not passed', $out);
        $this->assertStringContainsString('{closure}(): Argument #1 ($a) not passed', $out);
        $this->assertStringContainsString('Too few arguments to function f(), 0 passed', $out);
        $this->assertStringNotContainsString('{anonymous}', $out);
        // Named path must not use positional Too-few for the b:2 cases.
        $this->assertSame(1, substr_count($out, 'Too few arguments'));
    }

    public function testFormatUserFunctionNameMapsAnonymousClosure(): void
    {
        $this->assertSame('{closure}', ParamArgumentCountError::formatUserFunctionName('{anonymous}#1'));
        $this->assertSame('{closure}', ParamArgumentCountError::formatUserFunctionName('{closure}_3'));
        $this->assertSame(
            'class@anonymous::__construct',
            ParamArgumentCountError::formatUserFunctionName("class@anonymous\0/path/file.php:5\$0::__construct")
        );
    }
}
