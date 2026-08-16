<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * CachingIterator FULL_CACHE missing-key offsetGet → Undefined array key Warning (#31576).
 *
 * php-src: ext/spl/spl_iterators.c — spl_caching_it_offset_get / zend_hash_find
 */
final class Issue31576CachingIteratorOffsetGetWarnTest extends TestCase
{
    public function testVmMatchesZendUndefinedArrayKeyWarning(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_cachingiterator_offsetget_warn.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_cachingiterator_offsetget_warn.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "value=NULL\nwarning=Undefined array key \"x\"\n",
            $out
        );
    }
}
