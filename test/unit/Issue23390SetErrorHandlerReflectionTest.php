<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * set_error_handler Reflection names callback/error_levels (#23390).
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
final class Issue23390SetErrorHandlerReflectionTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['callback', 'error_levels='], BuiltinParamNames::forFunction('set_error_handler'));
        $names = BuiltinParamNames::forFunction('set_error_handler');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'set_error_handler'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'error_levels', 'set_error_handler'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'error_handler', 'set_error_handler'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'error_types', 'set_error_handler'));
    }

    public function testVmNamedCallbackMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23390_set_error_handler_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23390_set_error_handler_reflection.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "callback,error_levels\n"
            ."ok\n"
            ."ok_levels\n"
            ."Unknown named parameter \$error_handler\n"
            ."Unknown named parameter \$error_types\n",
            $out
        );
    }
}
