<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ob_get_clean Reflection string|false (#25593, basic_functions.stub.php). */
final class ObGetCleanReflection25593Test extends TestCase
{
    public function testReflectionReturnUnion(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_25593_ob_get_clean_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_25593.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "ob_get_clean=string|false\nempty=false\ngot='payload'\n",
            ob_get_clean()
        );
    }
}
