<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * set_exception_handler Reflection name callback (#23456).
 *
 * php-src: ext/standard/basic_functions.stub.php
 *
 * Note: AOT named-arg install for this builtin already aborts on master (positional
 * works); peer #23390 also gates Reflection/named via VM + compliance only.
 */
final class Issue23456SetExceptionHandlerReflectionTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['callback'], BuiltinParamNames::forFunction('set_exception_handler'));
        $names = BuiltinParamNames::forFunction('set_exception_handler');
        self::assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'callback', 'set_exception_handler'));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'exception_handler', 'set_exception_handler'));
    }

    public function testVmNamedCallbackMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23456_set_exception_handler_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23456_set_exception_handler_reflection.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "callback\n"
            ."ok\n"
            ."Unknown named parameter \$exception_handler\n",
            $out
        );
    }
}
