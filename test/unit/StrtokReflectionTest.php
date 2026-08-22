<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** strtok Reflection string|false (#25760, string.stub.php). */
final class StrtokReflectionTest extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25760_strtok_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25760.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "strtok=string|false\nfirst=1\nsecond=1\nend=1\n",
            ob_get_clean()
        );
    }
}
