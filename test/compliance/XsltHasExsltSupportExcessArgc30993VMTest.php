<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: XSLTProcessor::hasExsltSupport() excess argc → ArgumentCountError (#30993). */
final class XsltHasExsltSupportExcessArgc30993VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xslt_hasexsltsupport_30993.phpt' => self::parsePHPT(
            __DIR__.'/cases/xsl/excess_argc_xslt_hasexsltsupport_30993.phpt',
            'excess_argc_xslt_hasexsltsupport_30993.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
