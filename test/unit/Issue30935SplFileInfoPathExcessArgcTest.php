<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplFileInfo getBasename/getPath/getRealPath excess argc (#30935).
 *
 * php-src: ext/spl/spl_directory.c
 */
final class Issue30935SplFileInfoPathExcessArgcTest extends TestCase
{
    public function testVmArgcWordingMatchesZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_30935_splfileinfo_path_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_30935_splfileinfo_path_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'basename:ArgumentCountError:SplFileInfo::getBasename() expects at most 1 argument, 2 given',
            $out
        );
        $this->assertStringContainsString(
            'path:ArgumentCountError:SplFileInfo::getPath() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString(
            'real:ArgumentCountError:SplFileInfo::getRealPath() expects exactly 0 arguments, 1 given',
            $out
        );
        $this->assertStringContainsString('ok=1', $out);
    }
}
