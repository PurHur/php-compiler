<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** dl Reflection bool + enable_dl-off false (#28287, basic_functions.stub.php / dl.c). */
final class DlReflectionReturnTest extends TestCase
{
    public function testReflectionReturnBoolAndRuntimeFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28287_dl_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28287.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "dl=bool\nwarn=1\nresult=false\n",
            ob_get_clean()
        );
    }
}
