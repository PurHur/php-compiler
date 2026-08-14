<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * RecursiveDirectoryIterator getSubPath/getSubPathname excess argc (#30936).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue30936RdiSubPathExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30936_rdi_subpath_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30936_rdi_subpath_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'sub:ArgumentCountError:RecursiveDirectoryIterator::getSubPath() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'subname:ArgumentCountError:RecursiveDirectoryIterator::getSubPathname() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
    }
}
