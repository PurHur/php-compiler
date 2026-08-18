<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOMXPath //@* / attribute::* (#32003). */
final class DomXpathAttrStarJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_attr_star.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_attr_star.phpt',
            'dom_xpath_attr_star.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
