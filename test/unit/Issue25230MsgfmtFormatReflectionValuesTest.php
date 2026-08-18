<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * MessageFormatter::format Reflection $values + named values: (#25230).
 *
 * php-src: ext/intl/msgformat/msgformat.stub.php
 *
 * Force-registers the class so the named-dispatch overlay is exercised when host
 * php-intl is absent (php-src-strict advertisement gate #19670 / #22691).
 */
final class Issue25230MsgfmtFormatReflectionValuesTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['values'], BuiltinParamNames::forClassMethod('MessageFormatter::format'));
        self::assertSame(
            0,
            BuiltinParamNames::lookupNamedParamIndex(
                BuiltinParamNames::forClassMethod('MessageFormatter::format'),
                'values',
                'MessageFormatter::format'
            )
        );
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod('MessageFormatter::format'),
            'args',
            'MessageFormatter::format'
        ));
    }

    public function testVmNamedValuesMatchesZend(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        $code = file_get_contents(__DIR__.'/../repro/issue_25230_msgfmt_format_reflection_values.php');
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, 'issue_25230_msgfmt_format_reflection_values.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "values\nvalues=x\nargs:Unknown named parameter \$args\npos=x\n",
            $out
        );
    }
}
