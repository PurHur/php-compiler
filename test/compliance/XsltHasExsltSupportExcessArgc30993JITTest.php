<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: XSLTProcessor::hasExsltSupport() excess argc → ArgumentCountError (#30993). */
final class XsltHasExsltSupportExcessArgc30993JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_xslt_hasexsltsupport_30993_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/xsl/excess_argc_xslt_hasexsltsupport_30993_jit.phpt',
            'excess_argc_xslt_hasexsltsupport_30993_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
