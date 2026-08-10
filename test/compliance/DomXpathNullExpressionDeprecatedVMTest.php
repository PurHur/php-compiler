<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMXPath query/evaluate(null) Deprecated + Invalid expression (#30041). */
final class DomXpathNullExpressionDeprecatedVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_null_expression_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_null_expression_deprecated.phpt',
            'dom_xpath_null_expression_deprecated.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
