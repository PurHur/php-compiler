<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * DirectoryIterator / FilesystemIterator residual excess argc (#31009).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue31009DirItExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_31009_dirit_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31009_dirit_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        foreach ([
            'rewind: ArgumentCountError: DirectoryIterator::rewind() expects exactly 0 arguments, 1 given',
            'next: ArgumentCountError: DirectoryIterator::next() expects exactly 0 arguments, 1 given',
            'key: ArgumentCountError: DirectoryIterator::key() expects exactly 0 arguments, 1 given',
            'current: ArgumentCountError: DirectoryIterator::current() expects exactly 0 arguments, 1 given',
            'valid: ArgumentCountError: DirectoryIterator::valid() expects exactly 0 arguments, 1 given',
            'seek: ArgumentCountError: DirectoryIterator::seek() expects exactly 1 argument, 2 given',
            'setFlags: ArgumentCountError: FilesystemIterator::setFlags() expects exactly 1 argument, 2 given',
            'rewind_ok: OK',
            'valid_ok: OK',
            'seek_ok: OK',
            'setFlags_ok: OK',
        ] as $needle) {
            $this->assertStringContainsString($needle, $out);
        }
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
