<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DOMXPath parent/ancestor/sibling axes (#31773). */
final class DomXpathTreeAxesVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dom_xpath_tree_axes.phpt' => self::parsePHPT(
            __DIR__.'/cases/dom/dom_xpath_tree_axes.phpt',
            'dom_xpath_tree_axes.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
