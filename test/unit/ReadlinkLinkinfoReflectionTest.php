<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** readlink / linkinfo Reflection string|false / int|false (#28425, link.stub.php). */
final class ReadlinkLinkinfoReflectionTest extends TestCase
{
    public function testReflectionReturnUnions(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28425_readlink_linkinfo_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28425.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "readlink=string|false\nlinkinfo=int|false\nreadlink_missing=false\nlinkinfo_missing=-1\n",
            ob_get_clean()
        );
    }
}
