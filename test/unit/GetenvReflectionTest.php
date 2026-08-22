<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** getenv Reflection array|string|false (#26115, basic_functions.stub.php). */
final class GetenvReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_26115_getenv_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_26115.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "getenv=array|string|false\nnoarg=array\nnamed=1\n",
            ob_get_clean()
        );
    }
}
