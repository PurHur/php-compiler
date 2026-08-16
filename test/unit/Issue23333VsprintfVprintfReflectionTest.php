<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * vsprintf/vprintf Reflection names values (not args) (#23333).
 *
 * php-src: ext/standard/basic_functions.stub.php
 */
final class Issue23333VsprintfVprintfReflectionTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['format', 'values'], BuiltinParamNames::forFunction('vsprintf'));
        self::assertSame(['format', 'values'], BuiltinParamNames::forFunction('vprintf'));
        self::assertSame(1, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('vsprintf'),
            'values',
            'vsprintf'
        ));
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('vprintf'),
            'args',
            'vprintf'
        ));
    }

    public function testVmNamedValuesMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_23333_vsprintf_vprintf_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_23333_vsprintf_vprintf_reflection.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "vsprintf:format,values\n"
            ."vprintf:format,values\n"
            ."a-b\n"
            ."ok\n"
            ."Unknown named parameter \$args\n",
            $out
        );
    }
}
