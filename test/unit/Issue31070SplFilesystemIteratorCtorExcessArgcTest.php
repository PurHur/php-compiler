<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SPL filesystem iterator constructor excess argc (#31070).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue31070SplFilesystemIteratorCtorExcessArgcTest extends TestCase
{
    public function testVmCtorArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_spl_filesystem_iterator_ctor_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_spl_filesystem_iterator_ctor_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'GlobIterator: GlobIterator::__construct() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'RecursiveDirectoryIterator: RecursiveDirectoryIterator::__construct() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'FilesystemIterator: FilesystemIterator::__construct() expects at most 2 arguments, 3 given',
            $out
        );
        $this->assertStringContainsString(
            'DirectoryIterator: DirectoryIterator::__construct() expects exactly 1 argument, 2 given',
            $out
        );
        $this->assertStringNotContainsString('ACCEPTED', $out);
    }
}
