<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SPL iterator wrapper excess argc → Zend ArgumentCountError (#30949).
 *
 * php-src: ext/spl/spl_iterators.c
 */
final class Issue30949SplIteratorExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30949_spl_iterator_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30949_spl_iterator_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'ii:ArgumentCountError:IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'pos:ArgumentCountError:LimitIterator::getPosition() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'ligi:ArgumentCountError:IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'arr:ArgumentCountError:AppendIterator::getArrayIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'nr:ArgumentCountError:IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'inf:ArgumentCountError:IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'rx:ArgumentCountError:RegexIterator::getRegex() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'er:ArgumentCountError:EmptyIterator::rewind() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'pi:ArgumentCountError:RecursiveFilterIterator::getChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'rfi:ArgumentCountError:RecursiveFilterIterator::getChildren() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
    }
}
