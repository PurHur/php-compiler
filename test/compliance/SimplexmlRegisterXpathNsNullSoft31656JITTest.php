<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: SimpleXMLElement::registerXPathNamespace(null,…) soft-null DEP (#31656). */
final class SimplexmlRegisterXpathNsNullSoft31656JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'register_xpath_ns_null_soft.phpt' => self::parsePHPT(
            __DIR__.'/cases/simplexml/register_xpath_ns_null_soft.phpt',
            'register_xpath_ns_null_soft.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
