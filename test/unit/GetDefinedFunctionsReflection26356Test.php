<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_defined_functions Reflection vs Zend stubs (#26356). */
final class GetDefinedFunctionsReflection26356Test extends TestCase
{
    public function testReflectionExcludeDisabledBool(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_26356_get_defined_functions_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_26356.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "param=\$exclude_disabled:bool opt=yes def=true\n"
            ."return=array\n",
            ob_get_clean()
        );
    }
}
