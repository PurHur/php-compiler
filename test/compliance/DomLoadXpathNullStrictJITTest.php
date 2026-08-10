<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DOM loadXML/loadHTML + XPath query/evaluate null TypeError under strict_types (#30041).
 *
 * @group llvm
 * @group jit
 */
final class DomLoadXpathNullStrictJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_load_xpath_null_strict_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/dom_load_xpath_null_strict_jit.phpt',
            'dom_load_xpath_null_strict_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
