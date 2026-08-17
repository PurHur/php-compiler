<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** chop Reflection string return + typed params (#28255, string.stub.php). */
final class Issue28255ChopReflectionTest extends TestCase
{
    public function testReflectionMatchesZendStubs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28255_chop_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28255.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "return=string\n"
            .'$string type=string opt=n'."\n"
            .'$characters type=string opt=y'."\n"
            ."pos=[  a]\n"
            ."na=[  a]\n",
            ob_get_clean()
        );
    }
}
