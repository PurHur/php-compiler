<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject flags / iterator-class / getArrayCopy / user-sort excess argc (#30965).
 *
 * php-src: ext/spl/spl_array.c
 */
final class Issue30965ArrayObjectExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30965_arrayobject_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30965_arrayobject_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'getFlags: ArgumentCountError: ArrayObject::getFlags() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'setFlags: ArgumentCountError: ArrayObject::setFlags() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getIteratorClass: ArgumentCountError: ArrayObject::getIteratorClass() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'setIteratorClass: ArgumentCountError: ArrayObject::setIteratorClass() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'getArrayCopy: ArgumentCountError: ArrayObject::getArrayCopy() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'uasort: ArgumentCountError: ArrayObject::uasort() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'uksort: ArgumentCountError: ArrayObject::uksort() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString('getFlags_ok: OK', $out);
        $this->assertStringContainsString('setFlags_ok: OK', $out);
        $this->assertStringContainsString('getIteratorClass_ok: OK', $out);
        $this->assertStringContainsString('setIteratorClass_ok: OK', $out);
        $this->assertStringContainsString('getArrayCopy_ok: OK', $out);
        $this->assertStringContainsString('uasort_ok: OK', $out);
        $this->assertStringContainsString('uksort_ok: OK', $out);
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
