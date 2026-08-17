<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** JIT: DOMXPath //*[last()] per-parent child axis (#31923). */
final class DomXpathStarLastJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_star_last.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_star_last.phpt',
            'dom_xpath_star_last.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
