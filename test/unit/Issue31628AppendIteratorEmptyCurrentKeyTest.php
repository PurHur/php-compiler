<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Empty AppendIterator::current()/key() return null like Zend (#31628).
 *
 * php-src: ext/spl/spl_iterators.c — AppendIterator current/key when invalid
 */
final class Issue31628AppendIteratorEmptyCurrentKeyTest extends TestCase
{
    public function testVmMatchesZendEmptyCurrentKeyNull(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_appenditerator_current_empty.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_appenditerator_current_empty.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("valid=false\ncurrent=NULL\nkey=NULL\n", $out);
    }
}
