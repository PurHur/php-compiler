<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Excess argc → ArgumentCountError for link/upload path builtins (#30553).
 *
 * php-src: ext/standard/link.c / file.c / basic_functions.stub.php
 */
final class Issue30553LinkUploadExcessArgcTest extends TestCase
{
    public function testVmExcessArgcThrowsArgumentCountError(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_link_upload_excess_argc.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_link_upload_excess_argc.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            'symlink("/a", "/b", "x") => ArgumentCountError: symlink() expects exactly 2 arguments, 3 given'."\n"
            .'readlink("/a", "x") => ArgumentCountError: readlink() expects exactly 1 argument, 2 given'."\n"
            .'linkinfo("/a", "x") => ArgumentCountError: linkinfo() expects exactly 1 argument, 2 given'."\n"
            .'is_uploaded_file("/a", "x") => ArgumentCountError: is_uploaded_file() expects exactly 1 argument, 2 given'."\n"
            .'move_uploaded_file("/a", "/b", "x") => ArgumentCountError: move_uploaded_file() expects exactly 2 arguments, 3 given'."\n",
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('requires exactly', $out);
    }
}
