<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AppendIterator::getIteratorIndex() excess argc → Zend ArgumentCountError (#31041).
 *
 * php-src: ext/spl/spl_iterators.c — zim_AppendIterator_getIteratorIndex
 */
final class Issue31041AppendIteratorGetIteratorIndexArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31041_appenditerator_getiteratorindex_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31041_appenditerator_getiteratorindex_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ArgumentCountError: AppendIterator::getIteratorIndex() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=0', $out);
    }
}
