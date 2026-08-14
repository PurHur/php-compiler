<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFixedArray ArrayAccess excess argc (#30997).
 *
 * php-src: ext/spl/spl_fixedarray.c
 */
final class Issue30997SplFixedArrayOffsetExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30997_splfixedarray_offset_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30997_splfixedarray_offset_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'offsetGet: ArgumentCountError: SplFixedArray::offsetGet() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetSet: ArgumentCountError: SplFixedArray::offsetSet() expects exactly 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetExists: ArgumentCountError: SplFixedArray::offsetExists() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'offsetUnset: ArgumentCountError: SplFixedArray::offsetUnset() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('offsetGet_ok: OK', $out);
        $this->assertStringContainsString('offsetExists_ok: OK', $out);
        $this->assertStringContainsString('offsetSet_ok: OK', $out);
        $this->assertStringContainsString('offsetUnset_ok: OK', $out);
        $this->assertStringContainsString('after=11,0', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
