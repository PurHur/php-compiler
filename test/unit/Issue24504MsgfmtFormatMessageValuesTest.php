<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * msgfmt_format_message Reflection $values + named values: (#24504).
 *
 * php-src: ext/intl/msgformat/msgformat.stub.php
 *
 * Force-registers the builtin so the named-dispatch overlay is exercised when host
 * php-intl is absent (php-src-strict advertisement gate #19670 / #22691).
 */
final class Issue24504MsgfmtFormatMessageValuesTest extends TestCase
{
    public function testBuiltinParamNames(): void
    {
        self::assertSame(['locale', 'pattern', 'values'], BuiltinParamNames::forFunction('msgfmt_format_message'));
        self::assertSame(
            2,
            BuiltinParamNames::lookupNamedParamIndex(
                BuiltinParamNames::forFunction('msgfmt_format_message'),
                'values',
                'msgfmt_format_message'
            )
        );
        self::assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction('msgfmt_format_message'),
            'args',
            'msgfmt_format_message'
        ));
    }

    public function testVmNamedValuesMatchesZend(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_format_message());
        $code = file_get_contents(__DIR__.'/../repro/issue_24504_msgfmt_format_message_values.php');
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, 'issue_24504_msgfmt_format_message_values.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "locale,pattern,values\nvalues=Hi Ada\nargs:Unknown named parameter \$args\npos=Hi Ada\n",
            $out
        );
    }
}
