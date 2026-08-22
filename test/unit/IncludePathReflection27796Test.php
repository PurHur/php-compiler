<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_include_path/set_include_path Reflection vs Zend stubs (#27796). */
final class IncludePathReflection27796Test extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_27796_include_path_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_27796.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "get_include_path return=string|false\n"
            ."set_include_path return=string|false\n"
            ."param=\$include_path:string\n",
            ob_get_clean()
        );
    }
}
