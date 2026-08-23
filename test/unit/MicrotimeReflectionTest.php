<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** microtime Reflection string|float (#25967, basic_functions.stub.php). */
final class MicrotimeReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25967_microtime_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25967.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "microtime=string|float\nas_float=double\nas_string=string\n",
            ob_get_clean()
        );
    }
}
