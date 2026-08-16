<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * MultipleIterator::attachIterator duplicate info → Key duplication error (#31552).
 *
 * php-src: ext/spl/spl_observer.c — zim_MultipleIterator_attachIterator
 */
final class Issue31552MultipleIteratorKeyDuplicationTest extends TestCase
{
    public function testVmMatchesZendKeyDuplication(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_multipleiterator_key_duplication.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_multipleiterator_key_duplication.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("InvalidArgumentException: Key duplication error\n", $out);
    }
}
