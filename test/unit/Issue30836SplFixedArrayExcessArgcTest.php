<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFixedArray fromArray/toArray/setSize excess argc → ArgumentCountError (#30836).
 *
 * php-src: ext/spl/spl_fixedarray.c
 */
final class Issue30836SplFixedArrayExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_splfixedarray_excess_argc_30836.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_splfixedarray_excess_argc_30836.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'SplFixedArray::fromArray() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'SplFixedArray::toArray() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'SplFixedArray::setSize() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('ok_fromArray: 7', $out);
        $this->assertStringNotContainsString('ACCEPTED', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
