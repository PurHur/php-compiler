<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * FilterIterator / NoRewindIterator excess argc → Zend ArgumentCountError (#31678).
 *
 * php-src: ext/spl/spl_iterators.c — zim_FilterIterator_rewind, zim_NoRewindIterator_rewind
 */
final class Issue31678FilterNorewindRewindAceTest extends TestCase
{
    public function testVmRewindAndSiblingArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_filter_norewind_rewind_ace.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_filter_norewind_rewind_ace.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: FilterIterator::rewind() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ArgumentCountError: NoRewindIterator::rewind() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringNotContainsString('accepted', $out);
    }
}
