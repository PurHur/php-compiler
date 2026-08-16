<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getcwd Reflection string|false (#28174, basic_functions.stub.php). */
final class GetcwdReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28174_getcwd_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28174.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "getcwd=string|false\ncwd_ok=1\n",
            ob_get_clean()
        );
    }
}
