<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileObject residual excess argc (#31008).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue31008SplFileObjectExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31008_splfileobject_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31008_splfileobject_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'ftell: ArgumentCountError: SplFileObject::ftell() expects exactly 0 arguments, 1 given',
            'fstat: ArgumentCountError: SplFileObject::fstat() expects exactly 0 arguments, 1 given',
            'fpassthru: ArgumentCountError: SplFileObject::fpassthru() expects exactly 0 arguments, 1 given',
            'fread: ArgumentCountError: SplFileObject::fread() expects exactly 1 argument, 2 given',
            'fseek: ArgumentCountError: SplFileObject::fseek() expects at most 2 arguments, 3 given',
            'fwrite: ArgumentCountError: SplFileObject::fwrite() expects at most 2 arguments, 3 given',
            'flock: ArgumentCountError: SplFileObject::flock() expects at most 2 arguments, 3 given',
            'getFlags: ArgumentCountError: SplFileObject::getFlags() expects exactly 0 arguments, 1 given',
            'setFlags: ArgumentCountError: SplFileObject::setFlags() expects exactly 1 argument, 2 given',
            'getCsvControl: ArgumentCountError: SplFileObject::getCsvControl() expects exactly 0 arguments, 1 given',
            'setCsvControl: ArgumentCountError: SplFileObject::setCsvControl() expects at most 3 arguments, 4 given',
            'fgetcsv: ArgumentCountError: SplFileObject::fgetcsv() expects at most 3 arguments, 4 given',
            'rewind: ArgumentCountError: SplFileObject::rewind() expects exactly 0 arguments, 1 given',
            'next: ArgumentCountError: SplFileObject::next() expects exactly 0 arguments, 1 given',
            'key: ArgumentCountError: SplFileObject::key() expects exactly 0 arguments, 1 given',
            'current: ArgumentCountError: SplFileObject::current() expects exactly 0 arguments, 1 given',
            'valid: ArgumentCountError: SplFileObject::valid() expects exactly 0 arguments, 1 given',
            '__toString: ArgumentCountError: SplFileObject::__toString() expects exactly 0 arguments, 1 given',
            'hasChildren: ArgumentCountError: SplFileObject::hasChildren() expects exactly 0 arguments, 1 given',
            'getChildren: ArgumentCountError: SplFileObject::getChildren() expects exactly 0 arguments, 1 given',
            'ftell_ok: OK',
            'fread_ok: OK',
            'getFlags_ok: OK',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('COERCED', $out);
    }
}
