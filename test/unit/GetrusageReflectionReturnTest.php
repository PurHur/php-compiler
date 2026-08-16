<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getrusage Reflection array|false (#28841, basic_functions.stub.php). */
final class GetrusageReflectionReturnTest extends TestCase
{
    public function testReflectionReturnIsArrayOrFalse(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28841_getrusage_reflection_return.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28841.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "getrusage=array|false\ngetrusage_runtime=ok\n",
            ob_get_clean()
        );
    }
}
