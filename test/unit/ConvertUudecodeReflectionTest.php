<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** convert_uudecode Reflection string|false (#25536, string.stub.php / uuencode.c). */
final class ConvertUudecodeReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25536_convert_uudecode_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25536.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "convert_uudecode=string|false\nroundtrip=1\n",
            ob_get_clean()
        );
    }
}
