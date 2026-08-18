<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMXPath or/and predicates (#32050). */
final class DomXpathOrAndVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_or_and.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_or_and.phpt',
            'dom_xpath_or_and.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
