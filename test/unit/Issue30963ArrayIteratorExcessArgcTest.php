<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayIterator / RecursiveArrayIterator residual excess argc (#30963).
 *
 * php-src: ext/spl/spl_array.c
 */
final class Issue30963ArrayIteratorExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30963_arrayiterator_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30963_arrayiterator_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'seek: ArgumentCountError: ArrayIterator::seek() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getArrayCopy: ArgumentCountError: ArrayIterator::getArrayCopy() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'getFlags: ArgumentCountError: ArrayIterator::getFlags() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'setFlags: ArgumentCountError: ArrayIterator::setFlags() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetGet: ArgumentCountError: ArrayIterator::offsetGet() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'append: ArgumentCountError: ArrayIterator::append() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'hasChildren: ArgumentCountError: RecursiveArrayIterator::hasChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('seek_ok: OK', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
